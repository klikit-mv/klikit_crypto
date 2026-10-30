// Load test for the read-heavy API paths and paper order placement.
//   k6 run -e BASE_URL=https://staging.example.com -e EMAIL=trader@example.com -e PASSWORD=... tests/load/k6-api.js
// Never point this at production with real accounts.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { uuidv4 } from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

export const options = {
    scenarios: {
        browse: { executor: 'ramping-vus', startVUs: 0, stages: [{ duration: '1m', target: 50 }, { duration: '3m', target: 50 }, { duration: '30s', target: 0 }] },
        trade: { executor: 'constant-arrival-rate', rate: 5, timeUnit: '1s', duration: '4m', preAllocatedVUs: 10, exec: 'trade' },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{kind:read}': ['p(95)<400'],
        'http_req_duration{kind:order}': ['p(95)<800'],
    },
};

const BASE = __ENV.BASE_URL;

export function setup() {
    const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }), { headers: { 'Content-Type': 'application/json' } });
    const symbols = http.get(`${BASE}/api/v1/crypto/markets`, { headers: { Authorization: `Bearer ${res.json('token')}`, Accept: 'application/json' } });
    return { token: res.json('token'), symbolId: symbols.json('data').find((s) => s.symbol === 'ETH/USDT' && s.market_type === 'SPOT').id };
}

function headers(token, extra = {}) {
    return { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json', ...extra } };
}

export default function (data) {
    for (const path of ['/api/v1/dashboard/overview', '/api/v1/crypto/prices', '/api/v1/wallet/balances', '/api/v1/crypto/orders']) {
        const res = http.get(`${BASE}${path}`, { ...headers(data.token), tags: { kind: 'read' } });
        check(res, { [`${path} 200`]: (r) => r.status === 200 });
    }
    sleep(1);
}

export function trade(data) {
    const body = JSON.stringify({ symbol_id: data.symbolId, side: Math.random() < 0.5 ? 'buy' : 'sell', type: 'limit', quantity: '0.001', price: '100' });
    const res = http.post(`${BASE}/api/v1/crypto/orders`, body, { ...headers(data.token, { 'Idempotency-Key': uuidv4() }), tags: { kind: 'order' } });
    check(res, { 'order accepted or risk-rejected': (r) => [200, 201, 422, 429].includes(r.status) });
}
