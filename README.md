# Klikit Trading Platform — v1.0 foundation

Laravel 12 + MySQL 8 + Redis multi-asset trading platform (crypto, Forex, subscriptions, signals,
bots and mining management). This release covers the **v1.0 development package**:

- Complete MySQL schema for all modules (≈55 tables, including bots, Telegram signals and mining)
- Authentication (web sessions + Sanctum API tokens) and permission-based RBAC
- User and admin dashboards (Blade + Bootstrap 5)
- Subscription plans, entitlements, invoices, coupons and a manual payment gateway
- Exchange/broker integration framework with a paper-trading adapter
- Shared risk engine, double-entry ledger, wallets, deposits and withdrawals

Live order routing is **disabled by default** (`TRADING_LIVE_ENABLED=false`). Every order runs
against the paper-trading adapter using real stored market prices.

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
# Local without MySQL/Redis: set DB_CONNECTION=sqlite, QUEUE_CONNECTION=database, CACHE_STORE=database
php artisan migrate --seed          # roles, plans, venues, instruments, seed prices
php artisan serve
php artisan queue:work --queue=trading,default   # executes orders
php artisan schedule:work                         # price sync, limit-order matching, expiry
```

With `APP_ENV=local` the seeder creates `admin@example.com` (super admin) and
`trader@example.com`, both with password `password123`. In production create staff with
`php artisan admin:create you@company.com --role=super_admin`.

Tests: `php artisan test` (SQLite in-memory).

## Architecture

```
app/
├── Models/                      Eloquent models for every table
├── Modules/                     HTTP layer per module (controllers, requests, resources)
│   ├── Auth/  Dashboard/  Trading/  Wallet/  Subscriptions/  Administration/
├── Services/
│   ├── Access/                  AuditLogger, UserRegistrationService
│   ├── Accounting/              LedgerService (double entry), WalletService (locks)
│   ├── Subscriptions/           SubscriptionService (checkout, entitlements, expiry)
│   ├── Payments/                PaymentGateway contract, ManualPaymentGateway, PaymentService
│   ├── PlatformSettings.php     admin-editable runtime settings (halt, limits)
│   └── Trading/
│       ├── Contracts/           TradingVenueAdapter, ExchangeAdapter, BrokerAdapter, MarketDataProvider
│       ├── Adapters/            PaperTradingAdapter, CcxtBridgeExchangeAdapter, Mt5BridgeBrokerAdapter
│       ├── MarketData/          MarketDataService, BinancePublicMarketData (read-only)
│       ├── RiskManagementService.php
│       ├── TradingEngineService.php   order lifecycle
│       ├── SettlementService.php      fills → executions, trades, positions, ledger
│       ├── ExecutionRouter.php        paper / exchange / broker selection
│       └── PortfolioService.php       shared crypto + Forex valuation (USD)
├── Jobs/ExecuteOrderJob.php     runs on the dedicated `trading` queue
└── Http/Middleware/             active, feature:<key>, idempotent
config/trading.php  config/permissions.php  config/plans.php
routes/web.php  routes/api.php (/api/v1)  routes/admin.php (/admin)
```

### Order lifecycle

```
Manual / signal / bot  →  OrderIntent
   → RiskManagementService  (account, platform halt, user kill switch, plan, quantity,
                             leverage, stop-loss, per-trade size, daily loss, positions, exposure)
   → reserve funds (wallet lock)            ┐ one DB transaction
   → Order(status=pending)                  ┘ idempotency key unique per user
   → ExecuteOrderJob (trading queue)
   → ExecutionRouter → adapter.placeOrder()
   → SettlementService: executions, trades/positions, ledger postings, release reservation
```

Rejected orders are persisted with their reason and audited. Spot orders deliver the base
asset; FUTURES/FOREX/CFD open isolated-margin positions that are closed via
`POST /api/v1/positions/{id}/close`.

### Separation from the execution engine

Laravel never talks to exchanges directly in live mode. `CcxtBridgeExchangeAdapter` and
`Mt5BridgeBrokerAdapter` call a separate execution service over HTTP (`CCXT_BRIDGE_URL`,
`MT5_BRIDGE_URL`) with endpoints `/ticker`, `/orders`, `/orders/sync`, `/orders/cancel`,
`/positions/close`, `/balances`, `/market-hours`. The order `uuid` is sent as the client order
ID so retries are safe. Live accounts are non-custodial: funds stay at the venue and no
platform ledger postings are made for them.

### Accounting

- `LedgerService::post()` refuses any transaction that does not balance per currency.
- User wallets are liability accounts; cached `wallets.balance` is updated only inside the
  same DB transaction as the ledger entries, and can never go negative.
- `locked_balance` holds funds reserved by open orders, margin and pending withdrawals.
- `php artisan ledger:verify` (scheduled daily) recomputes every wallet from its entries.
- Deposits and withdrawals of `funding` wallets require staff review; staff cannot review
  their own withdrawals.

### Access control

Permissions live in `config/permissions.php` and are enforced through `Gate` (`@can`,
`can:` middleware, `$this->authorize()`), never by checking role names. Roles: Super Admin,
Admin, Manager, Support, Premium User, Standard User. No permission exposes users' exchange
secrets (encrypted with the app key, hidden from serialisation, shown masked) and no admin
permission lets staff trade on a user's behalf.

### Subscriptions

Entitlements are key/value features per plan (`config/plans.php` lists the keys). Users
without a plan get the `free` set (paper trading). Checkout creates a pending subscription and
an invoice; confirming the payment (admin or a future provider webhook) activates or extends
it. `subscriptions:expire` runs hourly. Use `feature:<key>` middleware or
`SubscriptionService::hasFeature()` to gate features.

## REST API (v1)

Bearer token from `POST /api/v1/auth/login`. Writes marked **(I)** require an
`Idempotency-Key` header (8–100 chars); replays return the stored response with
`Idempotent-Replayed: true`, a reused key with a different body returns 422.

| Area | Endpoints |
| --- | --- |
| Auth | `POST auth/register`, `POST auth/login`, `POST auth/logout`, `GET/PUT auth/profile` |
| Dashboard | `GET dashboard/overview?mode=paper`, `GET dashboard/statistics` |
| Crypto / Forex | `GET {crypto\|forex}/markets`, `/prices`, `/orders`, `POST /orders` (I), `GET /orders/{id}`, `POST /orders/{id}/cancel` (I), `GET /trades` |
| Positions | `GET positions`, `POST positions/{id}/close` (I) |
| Risk | `GET/PUT trading/risk-profile`, `POST trading/emergency-stop` |
| Venue accounts | `GET accounts`, `POST accounts/exchanges`, `POST accounts/brokers`, `DELETE accounts/{exchanges\|brokers}/{id}` |
| Wallet | `GET wallet/balances`, `/transactions`, `POST wallet/paper/reset`, `GET/POST wallet/deposits` (I), `GET/POST wallet/withdrawals` (I) |
| Subscriptions | `GET subscriptions/plans` (public), `/current`, `/invoices`, `POST /checkout` (I), `POST /cancel` |

Risk rejections return `422 {"message": ..., "rule": "max_amount_per_trade"}`.

## Scheduled jobs

| Command | Schedule | Purpose |
| --- | --- | --- |
| `market:sync` | every minute | Public Binance spot prices (read-only) |
| `trading:sync-orders` | every minute | Fill marketable paper limit orders / reconcile |
| `subscriptions:expire` | hourly | Expire lapsed plans and revert roles |
| `ledger:verify` | daily | Ledger and wallet reconciliation |

Forex prices have no free provider wired yet; add a `MarketDataProvider` for your broker feed
and register it in `AppServiceProvider`.

## Roadmap status

| Phase | Status |
| --- | --- |
| 1 Core platform | Done: auth, RBAC, dashboards, audit log |
| 2 Subscriptions | Done: plans, entitlements, invoices, coupons, manual payments, expiry. Next: Stripe/crypto gateway + webhooks |
| 3 Crypto & Forex | Framework done: adapters, paper trading, risk engine, ledger, positions. Next: execution bridge service, account verification, reconciliation before enabling live |
| 4 Telegram signals | Schema ready |
| 5 Chart analysis | Not started (TradingView Lightweight Charts) |
| 6 Trading bots | Schema + strategy catalogue ready |
| 7 Mining | Schema ready, dashboard widgets wired |
| 8 Production | Not started |

Before offering live trading, custody or paid services, confirm the financial-services,
brokerage, custody and AML/KYC requirements for each jurisdiction you operate in.
