#!/usr/bin/env bash
#
# Deploy the latest code on an aaPanel server.
#
# Usage (as root, from anywhere):
#   PHP_BIN=/www/server/php/82/bin/php /www/wwwroot/chat.hunario.com/scripts/deploy.sh
#
# Optional overrides: APP_DIR, PHP_BIN, COMPOSER_BIN, WEB_USER, BRANCH
#
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/chat.hunario.com}"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || echo /usr/local/bin/composer)}"
WEB_USER="${WEB_USER:-www}"
BRANCH="${BRANCH:-main}"

export COMPOSER_ALLOW_SUPERUSER=1

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }

command -v npm >/dev/null 2>&1 || { echo "npm not found in PATH (install Node.js from the aaPanel App Store)."; exit 1; }
[ -x "$PHP_BIN" ] || { echo "PHP binary not found: $PHP_BIN"; exit 1; }
[ -f "$COMPOSER_BIN" ] || { echo "Composer not found: $COMPOSER_BIN"; exit 1; }

cd "$APP_DIR"

step "Maintenance mode on"
"$PHP_BIN" artisan down --retry=15 || true

# Always bring the site back up, even if a step fails.
trap 'echo; echo "!! Deployment failed — see the output above. Bringing the site back up."; "$PHP_BIN" artisan up || true' ERR

step "Pulling ${BRANCH}"
git config --global --get-all safe.directory | grep -qx "$APP_DIR" || git config --global --add safe.directory "$APP_DIR"
git fetch origin "$BRANCH"
git pull --ff-only origin "$BRANCH"

step "Installing PHP dependencies"
"$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

step "Building frontend assets"
npm ci --no-audit --no-fund
npm run build

step "Running database migrations"
"$PHP_BIN" artisan migrate --force

step "Ensuring the public storage link"
[ -L public/storage ] || ln -sfn "$APP_DIR/storage/app/public" "$APP_DIR/public/storage"

step "Refreshing caches"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize
"$PHP_BIN" artisan view:cache

step "Fixing permissions"
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR" 2>/dev/null || true
chmod -R 775 storage bootstrap/cache
chmod 640 .env

step "Restarting Reverb and queue workers (Supervisor starts them again)"
"$PHP_BIN" artisan reverb:restart || true
"$PHP_BIN" artisan queue:restart || true

step "Maintenance mode off"
"$PHP_BIN" artisan up

trap - ERR
printf '\n\033[1;32mDeployment finished: %s\033[0m\n' "$(git log --oneline -1)"
