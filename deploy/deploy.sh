#!/usr/bin/env bash
# Zero-downtime-ish release on a single VPS: ./deploy/deploy.sh <git-ref>
set -euo pipefail
REF="${1:-main}"
BASE=/var/www/trading
RELEASE="$BASE/releases/$(date +%Y%m%d%H%M%S)"

git clone --depth 1 --branch "$REF" "$(git -C "$BASE/repo" remote get-url origin)" "$RELEASE"
ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
rm -rf "$RELEASE/storage" && ln -sfn "$BASE/shared/storage" "$RELEASE/storage"

cd "$RELEASE"
composer install --no-dev --optimize-autoloader --no-interaction
(cd services/execution-bridge && npm ci --omit=dev)
php artisan backup:database          # always back up before migrating
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

ln -sfn "$RELEASE" "$BASE/current"
sudo systemctl reload php8.3-fpm
php artisan horizon:terminate        # supervisor restarts workers on the new code
sudo supervisorctl restart trading-scheduler execution-bridge
ls -1dt "$BASE"/releases/* | tail -n +6 | xargs -r rm -rf
echo "Deployed $REF -> $RELEASE"
