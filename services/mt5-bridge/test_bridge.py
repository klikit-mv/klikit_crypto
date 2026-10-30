"""Unit tests with a fake MetaTrader5 module: python -m unittest test_bridge.py"""
import types
import unittest

import bridge


def fake_mt5():
    m = types.SimpleNamespace(
        TRADE_ACTION_DEAL=1, TRADE_ACTION_PENDING=5, TRADE_ACTION_REMOVE=8, ORDER_TYPE_BUY=0, ORDER_TYPE_SELL=1,
        ORDER_TYPE_BUY_LIMIT=2, ORDER_TYPE_SELL_LIMIT=3, ORDER_TIME_GTC=0, ORDER_FILLING_IOC=1, ORDER_FILLING_RETURN=2,
        TRADE_RETCODE_DONE=10009, TRADE_RETCODE_PLACED=10008, POSITION_TYPE_BUY=0, POSITION_TYPE_SELL=1,
        DEAL_ENTRY_IN=0, DEAL_ENTRY_OUT=1, DEAL_ENTRY_INOUT=2, ORDER_STATE_FILLED=4, ORDER_STATE_CANCELED=2,
        ORDER_STATE_REJECTED=5, ORDER_STATE_EXPIRED=6, SYMBOL_TRADE_MODE_FULL=4,
    )
    m.sent = []
    m.initialize = lambda **kw: True
    m.terminal_info = lambda: object()
    m.last_error = lambda: (0, "ok")
    m.symbol_select = lambda name, on: name == "EURUSD"
    m.symbol_info_tick = lambda name: types.SimpleNamespace(bid=1.0850, ask=1.0851, last=0)

    def order_send(req):
        m.sent.append(req)
        if req["action"] == m.TRADE_ACTION_PENDING:
            return types.SimpleNamespace(retcode=m.TRADE_RETCODE_PLACED, order=77, deal=0, price=0, volume=0, comment="")
        return types.SimpleNamespace(retcode=m.TRADE_RETCODE_DONE, order=55, deal=99, price=req["price"], volume=req["volume"], comment="done")

    m.order_send = order_send
    m.positions_get = lambda symbol: [types.SimpleNamespace(ticket=5, type=0, volume=0.3)]
    m.account_info = lambda: types.SimpleNamespace(currency="USD", balance=1000.0, trade_allowed=True)
    return m


class BridgeTest(unittest.TestCase):
    def setUp(self):
        bridge.mt5 = fake_mt5()
        bridge._current_login = None

    def test_market_order_fills_at_touch_with_sl_tp(self):
        result = bridge.dispatch("/orders", {"credentials": {"login": "1"}, "symbol": "EUR/USD", "side": "buy", "type": "market",
                                             "quantity": "0.10", "stop_loss": "1.08", "take_profit": "1.09", "client_order_id": "abc"})
        self.assertEqual(result["status"], "filled")
        self.assertEqual(result["fills"][0], {"price": "1.0851", "quantity": "0.1", "fee": "0", "fee_currency": "", "trade_id": "99"})
        self.assertEqual((bridge.mt5.sent[0]["sl"], bridge.mt5.sent[0]["tp"], bridge.mt5.sent[0]["comment"]), (1.08, 1.09, "abc"))

    def test_limit_order_is_placed_pending(self):
        result = bridge.dispatch("/orders", {"credentials": {}, "symbol": "EUR/USD", "side": "sell", "type": "limit", "quantity": "1", "price": "1.09"})
        self.assertEqual((result["status"], result["external_order_id"]), ("open", "77"))
        self.assertEqual(bridge.mt5.sent[0]["type"], bridge.mt5.ORDER_TYPE_SELL_LIMIT)

    def test_unknown_symbol(self):
        with self.assertRaises(bridge.BridgeError):
            bridge.dispatch("/ticker", {"credentials": {}, "symbol": "XXX/YYY"})

    def test_close_long_position_sells_at_bid(self):
        result = bridge.dispatch("/positions/close", {"credentials": {}, "symbol": "EUR/USD", "side": "long", "quantity": "0.3"})
        self.assertEqual((result["price"], result["quantity"]), ("1.085", "0.3"))
        self.assertEqual(bridge.mt5.sent[0]["position"], 5)

    def test_verify_reports_no_withdrawal(self):
        self.assertEqual(bridge.dispatch("/verify", {"credentials": {}}), {"ok": True, "can_trade": True, "can_withdraw": False, "balances": 1})


if __name__ == "__main__":
    unittest.main()
