import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createServer, ccxtSymbol } from '../app.js';

class FakeExchange {
    constructor() {
        this.id = 'fake';
        this.has = { fetchOrderTrades: true, setLeverage: true };
        this.calls = [];
    }
    async fetchTicker() { return { bid: 100, ask: 101, last: 100.5 }; }
    async setLeverage(lev, symbol) { this.calls.push(['setLeverage', lev, symbol]); }
    async createOrder(symbol, type, side, amount, price, params) {
        this.calls.push(['createOrder', symbol, type, side, amount, price, params]);
        return { id: 'X1', status: type === 'market' ? 'closed' : 'open', filled: type === 'market' ? amount : 0, average: 101 };
    }
    async fetchOrderTrades(id) {
        return id === 'X1' && this.calls.at(-1)?.[2] === 'market' ? [{ id: 'T1', price: 101, amount: 0.5, fee: { cost: 0.05, currency: 'USDT' } }] : [];
    }
    async fetchBalance() { return { total: { USDT: 1000, BTC: 0 } }; }
}

async function call(server, path, body, token = 'secret') {
    const { port } = server.address();
    const res = await fetch(`http://127.0.0.1:${port}${path}`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(body),
    });
    return { status: res.status, body: await res.json() };
}

async function withServer(fn) {
    const exchange = new FakeExchange();
    const server = createServer({ token: 'secret', exchangeFactory: () => exchange, log: {} });
    await new Promise((r) => server.listen(0, '127.0.0.1', r));
    try { await fn(server, exchange); } finally { server.close(); }
}

test('futures symbols map to linear perpetuals', () => {
    assert.equal(ccxtSymbol('BTC/USDT', 'FUTURES'), 'BTC/USDT:USDT');
    assert.equal(ccxtSymbol('BTC/USDT', 'SPOT'), 'BTC/USDT');
});

test('rejects requests without the bridge token', () => withServer(async (server) => {
    assert.equal((await call(server, '/ticker', {}, 'wrong')).status, 401);
}));

test('market order returns fills with trade ids and sets leverage', () => withServer(async (server, exchange) => {
    const { status, body } = await call(server, '/orders', {
        venue: 'fake', symbol: 'BTC/USDT', market_type: 'FUTURES', side: 'buy', type: 'market', quantity: '0.5', leverage: '5', client_order_id: 'uuid-1', stop_loss: '90',
    });
    assert.equal(status, 200);
    assert.deepEqual(body, { status: 'filled', external_order_id: 'X1', fills: [{ price: '101', quantity: '0.5', fee: '0.05', fee_currency: 'USDT', trade_id: 'T1' }] });
    assert.deepEqual(exchange.calls[0], ['setLeverage', 5, 'BTC/USDT:USDT']);
    assert.deepEqual(exchange.calls[1][6], { clientOrderId: 'uuid-1', stopLoss: { triggerPrice: 90 } });
}));

test('limit order stays open', () => withServer(async (server) => {
    const { body } = await call(server, '/orders', { venue: 'fake', symbol: 'BTC/USDT', market_type: 'SPOT', side: 'buy', type: 'limit', quantity: '1', price: '95' });
    assert.equal(body.status, 'open');
    assert.deepEqual(body.fills, []);
}));

test('verify and balances', () => withServer(async (server) => {
    assert.deepEqual((await call(server, '/verify', { venue: 'fake' })).body, { ok: true, can_trade: null, can_withdraw: null, balances: 2 });
    assert.deepEqual((await call(server, '/balances', { venue: 'fake' })).body, { balances: { USDT: '1000' } });
}));
