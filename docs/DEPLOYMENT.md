# Deployment

## Option A — Docker Compose (single host)

```bash
cp .env.example .env          # set APP_KEY, APP_URL, DB_*, CCXT_BRIDGE_TOKEN, STRIPE_*, MAIL_*
docker compose build
docker compose up -d mysql redis
docker compose run --rm app php artisan key:generate --force   # once
docker compose run --rm app php artisan migrate --force --seed
docker compose run --rm app php artisan admin:create you@company.com --role=super_admin
docker compose up -d
```

Services: `app` (PHP-FPM), `nginx`, `horizon` (queue workers), `scheduler`, `execution-bridge`
(CCXT), `mysql`, `redis`. Terminate TLS in front of `nginx` (load balancer or Caddy/Traefik).

## Option B — Ubuntu VPS + Nginx

1. Install PHP 8.3 (fpm, mysql, redis, intl, zip, bcmath, pcntl), MySQL 8, Redis, Node 22, Composer, Supervisor.
2. Layout: `/var/www/trading/{repo,releases,shared/{.env,storage}}`; clone into `repo`.
3. Nginx: `deploy/nginx/trading.conf` (TLS via certbot, request rate limits for auth and API).
4. PHP: copy `deploy/php/production.ini` into the FPM `conf.d`.
5. Supervisor: `deploy/supervisor/trading.conf` (Horizon, scheduler, execution bridge).
6. Release: `deploy/deploy.sh <git-ref>` — backs up the database, migrates, caches config,
   switches the `current` symlink and restarts workers.

MySQL: run with `--default-time-zone=+00:00` (all timestamps are UTC) and utf8mb4.

## MT5 bridge (Forex/CFD)

MetaTrader 5's Python API runs only on Windows next to a terminal:

```powershell
pip install MetaTrader5
$env:BRIDGE_TOKEN="..."; $env:HOST="0.0.0.0"; python services\mt5-bridge\bridge.py
```

Expose it only over a private network/VPN and set `MT5_BRIDGE_URL` / `MT5_BRIDGE_TOKEN`.
If your broker uses symbol suffixes (e.g. `EURUSD.m`), set `SYMBOL_SUFFIX`.

## Stripe

Set `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`, then create a webhook endpoint for
`https://<host>/webhooks/stripe` with events `checkout.session.completed`,
`checkout.session.async_payment_succeeded` and `checkout.session.expired`.
The card option appears at checkout only when Stripe is configured.

## Telegram

At `/admin/telegram` paste a bot token from @BotFather: the platform validates it and registers
its webhook (`/telegram/webhook/{bot}`, verified with a secret header). Add the bot as admin of
each channel and register the channel's chat ID. For channels where a bot cannot be added, a
forwarder can post messages to `POST /api/v1/signals/ingest/{channel}` with the channel's
`X-Signal-Secret`.

## Backups and recovery

- `backup:database` runs daily (mysqldump `--single-transaction`, gzip) to the `backups` disk;
  set `BACKUP_DISK_DRIVER=s3` + `BACKUP_BUCKET` for off-site storage. Retention: 14 days.
- Restore: `gunzip -c db-YYYYMMDD-HHMMSS.sql.gz | mysql -u <user> -p <database>`, then
  `php artisan ledger:verify` and `php artisan trading:sync-orders`.
- Also back up `.env` (contains `APP_KEY`; without it encrypted API keys cannot be decrypted)
  and the Redis AOF if you rely on queued jobs surviving restarts.
- Test a restore on staging every quarter.

## Monitoring

- Load balancer health check: `GET /health` (503 when database or cache is down).
- Alert on: `/health` warnings (scheduler heartbeat, stale market data), failed jobs
  (`/admin/failed-jobs`, Horizon), `ledger:verify` failures, bots in `error`, orders in `failed`.
- `HORIZON_ALERT_EMAIL` receives long-wait notifications (trading queue threshold: 5 s).

## Load testing

`k6 run -e BASE_URL=https://staging… -e EMAIL=… -e PASSWORD=… tests/load/k6-api.js` —
read-heavy browsing plus 5 paper orders/s with p95 thresholds. Run against staging only.

## Go-live checklist for live trading

1. Read-only phase: users connect keys with `permissions_scope=read_only`; run `accounts:verify`.
2. Paper phase: run strategies and signals in paper mode; review `ledger:verify` daily.
3. Deploy the execution bridge(s); verify `/health` on each bridge and a `trade`-scope key.
4. Reconciliation drills: kill a worker mid-order, take the bridge down, restart Redis;
   confirm orders end in `failed` or reconcile via `trading:sync-orders`, never duplicate.
5. Set conservative platform limits in `/admin/trading` and keep the emergency stop handy.
6. Set `TRADING_LIVE_ENABLED=true`, `php artisan config:cache`, restart Horizon.
7. Confirm regulatory requirements (brokerage, custody, AML/KYC) for every jurisdiction served.
