#!/usr/bin/env bash
#
# Make live updates work on an aaPanel server (Laravel Reverb WebSockets):
#   1. public Reverb settings in .env (keeps existing keys, creates missing ones)
#   2. Nginx proxy for /app/ (browsers, phones) and /apps/ (Laravel) in the site config
#      — backed up, tested with `nginx -t`, restored automatically if the test fails
#   3. Reverb kept running by systemd (only when nothing is running on the Reverb port yet)
#   4. HTTPS-only session cookie, config cache, Reverb restart, final check
#
# Usage (as root):
#   bash /www/wwwroot/chat.hunario.com/scripts/setup-realtime.sh
#
# Optional overrides: APP_DIR, PHP_BIN, DOMAIN, WEB_USER, NGINX_CONF, NGINX_BIN, REVERB_PORT_LOCAL
#
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/chat.hunario.com}"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
DOMAIN="${DOMAIN:-$(basename "$APP_DIR")}"
WEB_USER="${WEB_USER:-www}"
NGINX_CONF="${NGINX_CONF:-/www/server/panel/vhost/nginx/${DOMAIN}.conf}"
NGINX_BIN="${NGINX_BIN:-$(command -v nginx || echo /www/server/nginx/sbin/nginx)}"
REVERB_PORT_LOCAL="${REVERB_PORT_LOCAL:-8080}"
ENV_FILE="$APP_DIR/.env"
SERVICE_NAME="one2one-reverb"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
ok() { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m⚠\033[0m %s\n' "$*"; }
die() { printf '\n\033[31m✘ %s\033[0m\n' "$*"; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run this script as root."
[ -f "$ENV_FILE" ] || die ".env not found in $APP_DIR (set APP_DIR=...)."
[ -x "$PHP_BIN" ] || die "PHP not found: $PHP_BIN (set PHP_BIN=...)."

env_get() {
    { grep -E "^$1=" "$ENV_FILE" || true; } | tail -n 1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

env_set() {
    local key="$1" value="$2" escaped
    escaped=$(printf '%s' "$value" | sed -e 's/[\/&|]/\\&/g')
    if grep -qE "^${key}=" "$ENV_FILE"; then
        sed -i -E "s|^${key}=.*|${key}=${escaped}|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

# ---------------------------------------------------------------------------
step "Reverb settings in .env"
cp "$ENV_FILE" "$ENV_FILE.bak.$(date +%Y%m%d%H%M%S)"

[ -n "$(env_get REVERB_APP_ID)" ] || env_set REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
[ -n "$(env_get REVERB_APP_KEY)" ] || env_set REVERB_APP_KEY "$(openssl rand -hex 16)"
[ -n "$(env_get REVERB_APP_SECRET)" ] || env_set REVERB_APP_SECRET "$(openssl rand -hex 32)"

env_set BROADCAST_CONNECTION reverb
env_set REVERB_HOST "$DOMAIN"
env_set REVERB_PORT 443
env_set REVERB_SCHEME https
env_set REVERB_SERVER_HOST 127.0.0.1
env_set REVERB_SERVER_PORT "$REVERB_PORT_LOCAL"
env_set REVERB_ALLOWED_ORIGINS "$DOMAIN"
env_set SESSION_SECURE_COOKIE true
ok "REVERB_HOST=$DOMAIN, port 443 (https) — Reverb itself listens on 127.0.0.1:$REVERB_PORT_LOCAL"

# ---------------------------------------------------------------------------
step "Nginx proxy for /app/ and /apps/"
if [ ! -f "$NGINX_CONF" ]; then
    warn "Site config not found: $NGINX_CONF — add the blocks from docs/DEPLOYMENT-AAPANEL.md step 10 by hand."
elif grep -q 'location \^~ /app/' "$NGINX_CONF"; then
    ok "Proxy blocks already present"
else
    BACKUP="$NGINX_CONF.bak.$(date +%Y%m%d%H%M%S)"
    cp "$NGINX_CONF" "$BACKUP"

    BLOCK=$(mktemp)
    cat > "$BLOCK" <<NGINX

    # ---------- Laravel Reverb (added by scripts/setup-realtime.sh) ----------
    location ^~ /apps/ {
        proxy_pass http://127.0.0.1:${REVERB_PORT_LOCAL};
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location ^~ /app/ {
        proxy_pass http://127.0.0.1:${REVERB_PORT_LOCAL};
        proxy_http_version 1.1;
        proxy_set_header Host \$http_host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
        proxy_buffering off;
    }
    # --------------------------------------------------------------------------
NGINX
    grep -q 'fastcgi_intercept_errors off' "$NGINX_CONF" || sed -i '2i\    fastcgi_intercept_errors off;' "$BLOCK"

    # aaPanel marks the SSL section; otherwise insert after the first server_name line.
    if grep -q '#SSL-END' "$NGINX_CONF"; then
        MARKER='#SSL-END'
    else
        MARKER='server_name'
    fi
    awk -v marker="$MARKER" -v block="$BLOCK" '
        { print }
        !done && index($0, marker) { while ((getline line < block) > 0) print line; done = 1 }
    ' "$BACKUP" > "$NGINX_CONF"
    rm -f "$BLOCK"

    if "$NGINX_BIN" -t >/dev/null 2>&1; then
        "$NGINX_BIN" -s reload
        ok "Proxy added and Nginx reloaded (backup: $BACKUP)"
    else
        cp "$BACKUP" "$NGINX_CONF"
        "$NGINX_BIN" -t || true
        die "Nginx rejected the change; the original config was restored. Add the blocks by hand (guide step 10)."
    fi
fi

# ---------------------------------------------------------------------------
step "Applying settings"
cd "$APP_DIR"
"$PHP_BIN" artisan config:cache >/dev/null
chown -R "$WEB_USER":"$WEB_USER" bootstrap/cache storage
ok "Configuration cached"

# ---------------------------------------------------------------------------
step "Reverb process"
port_listening() { ss -ltn 2>/dev/null | grep -qE "[:.]$REVERB_PORT_LOCAL\s"; }

if port_listening; then
    "$PHP_BIN" artisan reverb:restart >/dev/null 2>&1 || true
    ok "Reverb is running on port $REVERB_PORT_LOCAL (restart requested so it reads the new settings)"
    warn "If it does not come back within a few seconds, check your Supervisor daemon (guide step 11)."
elif grep -rqs "reverb:start" /www/server/panel/plugin/supervisor /etc/supervisor /etc/supervisord.d 2>/dev/null; then
    warn "A Supervisor daemon for Reverb exists but Reverb is not running."
    warn "Start it in aaPanel → App Store → Supervisor manager (and check its log), then run this script again."
else
    cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<UNIT
[Unit]
Description=One2One Chat - Laravel Reverb WebSocket server
After=network.target

[Service]
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} artisan reverb:start --host=127.0.0.1 --port=${REVERB_PORT_LOCAL}
Restart=always
RestartSec=3
LimitNOFILE=10000

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now "${SERVICE_NAME}.service" >/dev/null 2>&1
    sleep 3
    if port_listening; then
        ok "Reverb started and enabled at boot (systemctl status ${SERVICE_NAME})"
    else
        systemctl status "${SERVICE_NAME}.service" --no-pager || true
        die "Reverb did not start — see the log above."
    fi
fi

# ---------------------------------------------------------------------------
step "Checking"
sleep 2
chown -R "$WEB_USER":"$WEB_USER" bootstrap/cache storage
runuser -u "$WEB_USER" -- "$PHP_BIN" artisan chat:doctor || true

printf '\nDone. Reload the chat in the browser: the connection should now show "Live updates via WebSocket".\n'
printf 'Phones pick up the new settings the next time the app is opened.\n'
