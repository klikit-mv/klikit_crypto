# Security model

## Secrets

| Secret | Storage | Who can read it |
| --- | --- | --- |
| Exchange/broker API keys | `encrypted:array` cast (APP_KEY), hidden from serialisation | Only the execution adapters, sent per request to the private bridge |
| Telegram bot tokens, pool API keys | `encrypted` casts | Only the Telegram/pool clients |
| Mining agent tokens | SHA-256 hash only | Shown once to the device owner |
| Telegram webhook secret | Derived HMAC of the bot id with APP_KEY | Telegram (header), never stored |
| Bridge tokens, Stripe keys | `.env` | Servers only |

No admin permission exposes secrets or lets staff place trades for users. Staff can halt
platform trading, stop bots and review deposits/withdrawals (never their own).

## Request protection

- Session auth with CSRF; Sanctum bearer tokens for the API; suspended users lose tokens.
- Rate limits: auth 10/min/IP, API 120/min/user, trading 30/min/user (plus nginx limits).
- Idempotency keys on every financial write; unique `(user_id, idempotency_key)` on orders.
- Webhooks verify signatures: Stripe (`Stripe-Signature`, 5-minute tolerance, amount check),
  Telegram (`X-Telegram-Bot-Api-Secret-Token`), signal ingest (`X-Signal-Secret`).
- Security headers on every response: CSP, `X-Frame-Options: DENY`, `nosniff`,
  `Referrer-Policy`, `Permissions-Policy`, HSTS over HTTPS.
- Exchange keys with withdrawal permission are rejected at verification.

## Financial integrity

- Double-entry ledger, per-currency balance check on every posting, no negative wallets,
  daily `ledger:verify`.
- Funds are reserved (wallet locks) in the same transaction that creates an order.
- Order execution jobs are not retried; stuck live orders are reconciled by client order id.
- All risk checks apply to manual, signal and bot orders alike.

## Pre-launch checklist

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, unique `APP_KEY` stored in a secrets manager
- [ ] HTTPS only, `SESSION_SECURE_COOKIE=true`
- [ ] MySQL and Redis not reachable from the internet; bridges on a private network
- [ ] `BRIDGE_TOKEN`s are long random strings and differ per bridge
- [ ] Off-site backups configured and a restore tested
- [ ] Staff accounts use strong unique passwords (`admin:create` enforces 12+ chars with symbols)
- [ ] Dependency audit (`composer audit`, `npm audit`) clean
- [ ] Penetration test of auth, payments, webhooks and order endpoints
- [ ] Regulatory review (brokerage, custody, AML/KYC) completed
