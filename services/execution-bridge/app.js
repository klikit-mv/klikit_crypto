// Stateless HTTP bridge between the Laravel platform and crypto exchanges via CCXT.
// Credentials arrive with each request (decrypted by Laravel) and are never stored here.
import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import ccxt from 'ccxt';

const STATUS = { closed: 'filled', canceled: 'cancelled', cancelled: 'cancelled', expired: 'cancelled', rejected: 'rejected' };

export function defaultExchangeFactory({ venue, credentials = {}, marketType }) {
    const Exchange = ccxt[venue];
    if (!Exchange) throw httpError(400, `Unsupported exchange: ${venue}`);
    return new Exchange({
        apiKey: credentials.api_key,
        secret: credentials.api_secret,
        password: credentials.passphrase,
        enableRateLimit: true,
        options: { defaultType: marketType === 'FUTURES' ? 'swap' : 'spot' },
    });
}

export function ccxtSymbol(symbol, marketType) {
    if (marketType !== 'FUTURES' || symbol.includes(':')) return symbol;
    return `${symbol}:${symbol.split('/')[1]}`; // BTC/USDT -> BTC/USDT:USDT (linear perpetual)
}

function httpError(status, message) {
    return Object.assign(new Error(message), { status });
}

function errorStatus(error) {
    if (error.status) return error.status;
    if (error instanceof ccxt.AuthenticationError || error instanceof ccxt.PermissionDenied) return 401;
    if (error instanceof ccxt.InsufficientFunds || error instanceof ccxt.InvalidOrder || error instanceof ccxt.BadRequest) return 422;
    if (error instanceof ccxt.OrderNotFound) return 404;
    if (error instanceof ccxt.RateLimitExceeded || error instanceof ccxt.DDoSProtection) return 429;
    if (error instanceof ccxt.NetworkError || error instanceof ccxt.ExchangeNotAvailable) return 503;
    return 500;
}

async function fillsFor(exchange, order, symbol) {
    if (exchange.has?.fetchOrderTrades) {
        try {
            const trades = await exchange.fetchOrderTrades(order.id, symbol);
            if (trades.length) return trades.map(toFill);
        } catch { /* fall back below */ }
    }
    if (order.trades?.length) return order.trades.map(toFill);
    // Without per-trade data, report one fill only once the order is complete (stable id => no double count).
    if (order.status === 'closed' && order.filled > 0) {
        return [{ price: String(order.average ?? order.price), quantity: String(order.filled), fee: String(order.fee?.cost ?? 0), fee_currency: order.fee?.currency ?? '', trade_id: `${order.id}:final` }];
    }
    return [];
}

function toFill(trade) {
    return { price: String(trade.price), quantity: String(trade.amount), fee: String(trade.fee?.cost ?? 0), fee_currency: trade.fee?.currency ?? '', trade_id: String(trade.id) };
}

async function toResult(exchange, order, symbol) {
    let status = STATUS[order.status] ?? 'open';
    if (status === 'open' && order.filled > 0) status = 'partially_filled';
    return { status, external_order_id: String(order.id), fills: await fillsFor(exchange, order, symbol) };
}

export const handlers = {
    async '/ticker'(exchange, body) {
        const t = await exchange.fetchTicker(ccxtSymbol(body.symbol, body.market_type));
        return { bid: String(t.bid), ask: String(t.ask), last: String(t.last) };
    },

    async '/orders'(exchange, body) {
        const symbol = ccxtSymbol(body.symbol, body.market_type);
        if (body.market_type === 'FUTURES' && exchange.has?.setLeverage && Number(body.leverage) > 1) {
            await exchange.setLeverage(Number(body.leverage), symbol);
        }
        const params = { clientOrderId: body.client_order_id };
        if (body.stop_loss) params.stopLoss = { triggerPrice: Number(body.stop_loss) };
        if (body.take_profit) params.takeProfit = { triggerPrice: Number(body.take_profit) };
        const price = body.type === 'limit' ? Number(body.price) : undefined;
        const order = await exchange.createOrder(symbol, body.type, body.side, Number(body.quantity), price, params);
        return toResult(exchange, order, symbol);
    },

    async '/orders/sync'(exchange, body) {
        const symbol = ccxtSymbol(body.symbol, body.market_type);
        const order = body.external_order_id
            ? await exchange.fetchOrder(body.external_order_id, symbol)
            : await exchange.fetchOrder(undefined, symbol, { clientOrderId: body.client_order_id, origClientOrderId: body.client_order_id });
        return toResult(exchange, order, symbol);
    },

    async '/orders/cancel'(exchange, body) {
        await exchange.cancelOrder(body.external_order_id, ccxtSymbol(body.symbol, body.market_type));
        return { cancelled: true };
    },

    async '/positions/close'(exchange, body) {
        const symbol = ccxtSymbol(body.symbol, 'FUTURES');
        const side = body.side === 'long' ? 'sell' : 'buy';
        const order = await exchange.createOrder(symbol, 'market', side, Number(body.quantity), undefined, { reduceOnly: true });
        const fills = await fillsFor(exchange, order, symbol);
        const fee = fills.reduce((sum, f) => sum + Number(f.fee), 0);
        return { price: String(order.average ?? fills[0]?.price), quantity: String(order.filled ?? body.quantity), fee: String(fee), fee_currency: fills[0]?.fee_currency ?? '' };
    },

    async '/balances'(exchange) {
        const balance = await exchange.fetchBalance();
        const totals = Object.fromEntries(Object.entries(balance.total ?? {}).filter(([, v]) => Number(v) > 0).map(([k, v]) => [k, String(v)]));
        return { balances: totals };
    },

    async '/verify'(exchange) {
        try {
            const balance = await exchange.fetchBalance();
            const permissions = await apiPermissions(exchange);
            return { ok: true, ...permissions, balances: Object.keys(balance.total ?? {}).length };
        } catch (error) {
            if (error instanceof ccxt.AuthenticationError || error instanceof ccxt.PermissionDenied) {
                return { ok: false, message: 'Credentials were rejected by the exchange.' };
            }
            throw error;
        }
    },
};

// Exchanges that expose key restrictions; others report null (unknown).
async function apiPermissions(exchange) {
    if (exchange.id === 'binance' && typeof exchange.sapiGetAccountApiRestrictions === 'function') {
        const r = await exchange.sapiGetAccountApiRestrictions();
        return { can_trade: Boolean(r.enableSpotAndMarginTrading || r.enableFutures), can_withdraw: Boolean(r.enableWithdrawals) };
    }
    return { can_trade: null, can_withdraw: null };
}

export function createServer({ token, exchangeFactory = defaultExchangeFactory, log = console } = {}) {
    if (!token) throw new Error('BRIDGE_TOKEN must be set');
    const expected = Buffer.from(`Bearer ${token}`);

    return http.createServer(async (req, res) => {
        const send = (status, payload) => {
            res.writeHead(status, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify(payload));
        };

        if (req.method === 'GET' && req.url === '/health') return send(200, { ok: true });

        const auth = Buffer.from(req.headers.authorization ?? '');
        if (auth.length !== expected.length || !timingSafeEqual(auth, expected)) return send(401, { message: 'Unauthorized' });

        const handler = req.method === 'POST' ? handlers[req.url] : undefined;
        if (!handler) return send(404, { message: 'Not found' });

        let body;
        try {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            body = JSON.parse(Buffer.concat(chunks).toString() || '{}');
        } catch {
            return send(400, { message: 'Invalid JSON' });
        }

        try {
            const exchange = exchangeFactory({ venue: body.venue, credentials: body.credentials, marketType: body.market_type });
            send(200, await handler(exchange, body));
        } catch (error) {
            const status = errorStatus(error);
            // Never log request bodies: they contain credentials.
            log.error?.(`${req.url} ${body.venue ?? '?'} -> ${status}: ${error.message}`);
            send(status, { message: error.message });
        }
    });
}
