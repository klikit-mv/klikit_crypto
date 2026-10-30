"""
MT5 execution bridge for the trading platform (Forex / CFD).

Runs on a Windows host next to a MetaTrader 5 terminal:

    pip install MetaTrader5
    set BRIDGE_TOKEN=... & python bridge.py            (listens on 127.0.0.1:8082)

Implements the same JSON contract as the CCXT bridge: /ticker, /orders, /orders/sync,
/orders/cancel, /positions/close, /balances, /verify and /market-hours. Credentials
(login, password, server) arrive with each request; one terminal serves one account at a
time, so calls are serialised and the terminal re-logs in when the account changes.
Quantities are in lots.
"""
import hmac
import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    import MetaTrader5 as mt5
except ImportError:  # allows the unit tests to inject a fake module
    mt5 = None

TOKEN = os.environ.get("BRIDGE_TOKEN", "")
SUFFIX = os.environ.get("SYMBOL_SUFFIX", "")  # some brokers use e.g. "EURUSD.m"
MAGIC = int(os.environ.get("MAGIC_NUMBER", "730001"))
DEVIATION = int(os.environ.get("MAX_DEVIATION_POINTS", "20"))
_lock = threading.Lock()
_current_login = None


class BridgeError(Exception):
    def __init__(self, status, message):
        super().__init__(message)
        self.status = status


def broker_symbol(symbol):
    return symbol.replace("/", "") + SUFFIX


def login(credentials):
    global _current_login
    login_id = int(credentials.get("login", 0))
    if _current_login == login_id and mt5.terminal_info() is not None:
        return
    if not mt5.initialize(login=login_id, password=credentials.get("password", ""), server=credentials.get("server", "")):
        _current_login = None
        raise BridgeError(401, f"MT5 login failed: {mt5.last_error()}")
    _current_login = login_id


def tick(symbol):
    name = broker_symbol(symbol)
    if not mt5.symbol_select(name, True):
        raise BridgeError(422, f"Unknown symbol {name}")
    t = mt5.symbol_info_tick(name)
    if t is None:
        raise BridgeError(503, f"No price for {name}")
    return name, t


def deal_fills(deals):
    return [{
        "price": str(d.price), "quantity": str(d.volume),
        "fee": str(abs(d.commission) + abs(getattr(d, "fee", 0))), "fee_currency": "", "trade_id": str(d.ticket),
    } for d in deals or [] if d.entry in (mt5.DEAL_ENTRY_IN, mt5.DEAL_ENTRY_OUT, mt5.DEAL_ENTRY_INOUT)]


def handle_ticker(body):
    _, t = tick(body["symbol"])
    return {"bid": str(t.bid), "ask": str(t.ask), "last": str(t.last or (t.bid + t.ask) / 2)}


def handle_orders(body):
    name, t = tick(body["symbol"])
    buy = body["side"] == "buy"
    market = body["type"] == "market"
    request = {
        "action": mt5.TRADE_ACTION_DEAL if market else mt5.TRADE_ACTION_PENDING,
        "symbol": name,
        "volume": float(body["quantity"]),
        "type": (mt5.ORDER_TYPE_BUY if buy else mt5.ORDER_TYPE_SELL) if market else (mt5.ORDER_TYPE_BUY_LIMIT if buy else mt5.ORDER_TYPE_SELL_LIMIT),
        "price": (t.ask if buy else t.bid) if market else float(body["price"]),
        "deviation": DEVIATION,
        "magic": MAGIC,
        "comment": str(body.get("client_order_id", ""))[:31],
        "type_time": mt5.ORDER_TIME_GTC,
        "type_filling": mt5.ORDER_FILLING_IOC if market else mt5.ORDER_FILLING_RETURN,
    }
    if body.get("stop_loss"):
        request["sl"] = float(body["stop_loss"])
    if body.get("take_profit"):
        request["tp"] = float(body["take_profit"])

    result = mt5.order_send(request)
    if result is None:
        raise BridgeError(503, f"order_send failed: {mt5.last_error()}")
    if result.retcode == mt5.TRADE_RETCODE_PLACED:
        return {"status": "open", "external_order_id": str(result.order), "fills": []}
    if result.retcode != mt5.TRADE_RETCODE_DONE:
        return {"status": "rejected", "external_order_id": str(result.order or ""), "fills": [], "message": f"MT5 retcode {result.retcode}: {result.comment}"}
    return {"status": "filled", "external_order_id": str(result.order), "fills": [{
        "price": str(result.price), "quantity": str(result.volume), "fee": "0", "fee_currency": "", "trade_id": str(result.deal),
    }]}


def handle_sync(body):
    ticket = int(body.get("external_order_id") or 0)
    if ticket and mt5.orders_get(ticket=ticket):
        return {"status": "open", "external_order_id": str(ticket), "fills": []}
    history = mt5.history_orders_get(ticket=ticket) if ticket else None
    if not history:
        raise BridgeError(404, "Order not found")
    order = history[0]
    status = {mt5.ORDER_STATE_FILLED: "filled", mt5.ORDER_STATE_CANCELED: "cancelled", mt5.ORDER_STATE_REJECTED: "rejected", mt5.ORDER_STATE_EXPIRED: "cancelled"}.get(order.state, "open")
    return {"status": status, "external_order_id": str(ticket), "fills": deal_fills(mt5.history_deals_get(position=order.position_id))}


def handle_cancel(body):
    result = mt5.order_send({"action": mt5.TRADE_ACTION_REMOVE, "order": int(body["external_order_id"])})
    return {"cancelled": bool(result and result.retcode == mt5.TRADE_RETCODE_DONE)}


def handle_close(body):
    name, t = tick(body["symbol"])
    want = mt5.POSITION_TYPE_BUY if body["side"] == "long" else mt5.POSITION_TYPE_SELL
    remaining = float(body["quantity"])
    fills = []
    for position in mt5.positions_get(symbol=name) or []:
        if position.type != want or remaining <= 0:
            continue
        volume = min(position.volume, remaining)
        result = mt5.order_send({
            "action": mt5.TRADE_ACTION_DEAL, "symbol": name, "volume": volume, "position": position.ticket,
            "type": mt5.ORDER_TYPE_SELL if want == mt5.POSITION_TYPE_BUY else mt5.ORDER_TYPE_BUY,
            "price": t.bid if want == mt5.POSITION_TYPE_BUY else t.ask,
            "deviation": DEVIATION, "magic": MAGIC, "type_filling": mt5.ORDER_FILLING_IOC,
        })
        if result is None or result.retcode != mt5.TRADE_RETCODE_DONE:
            raise BridgeError(422, f"Close failed: {getattr(result, 'comment', mt5.last_error())}")
        fills.append((result.price, volume))
        remaining -= volume
    if not fills:
        raise BridgeError(404, "No matching open position")
    qty = sum(v for _, v in fills)
    return {"price": str(sum(p * v for p, v in fills) / qty), "quantity": str(qty), "fee": "0", "fee_currency": ""}


def handle_balances(body):
    info = mt5.account_info()
    return {"balances": {info.currency: str(info.balance)}}


def handle_verify(body):
    info = mt5.account_info()
    if info is None:
        return {"ok": False, "message": "Account information unavailable."}
    # MT5 trading accounts cannot withdraw through the terminal API.
    return {"ok": True, "can_trade": bool(info.trade_allowed), "can_withdraw": False, "balances": 1}


def handle_market_hours(body):
    name = broker_symbol(body["symbol"])
    info = mt5.symbol_info(name)
    return {"open": bool(info and info.trade_mode == mt5.SYMBOL_TRADE_MODE_FULL and info.session_deals is not None and mt5.symbol_info_tick(name) is not None)}


HANDLERS = {
    "/ticker": handle_ticker, "/orders": handle_orders, "/orders/sync": handle_sync, "/orders/cancel": handle_cancel,
    "/positions/close": handle_close, "/balances": handle_balances, "/verify": handle_verify, "/market-hours": handle_market_hours,
}


def dispatch(path, body):
    handler = HANDLERS.get(path)
    if handler is None:
        raise BridgeError(404, "Not found")
    with _lock:
        login(body.get("credentials") or {})
        return handler(body)


class Handler(BaseHTTPRequestHandler):
    def _send(self, status, payload):
        data = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        self._send(200, {"ok": True}) if self.path == "/health" else self._send(404, {"message": "Not found"})

    def do_POST(self):
        if not hmac.compare_digest(self.headers.get("Authorization", ""), f"Bearer {TOKEN}"):
            return self._send(401, {"message": "Unauthorized"})
        try:
            body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))) or b"{}")
            self._send(200, dispatch(self.path, body))
        except BridgeError as e:
            self._send(e.status, {"message": str(e)})
        except Exception as e:  # never echo request bodies: they contain credentials
            self._send(500, {"message": f"{type(e).__name__}: {e}"})

    def log_message(self, fmt, *args):
        pass  # keep credentials out of logs


if __name__ == "__main__":
    if not TOKEN or mt5 is None:
        raise SystemExit("Set BRIDGE_TOKEN and install the MetaTrader5 package (Windows).")
    host, port = os.environ.get("HOST", "127.0.0.1"), int(os.environ.get("PORT", "8082"))
    print(f"MT5 bridge listening on {host}:{port}")
    ThreadingHTTPServer((host, port), Handler).serve_forever()
