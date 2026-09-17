#!/usr/bin/env bash
#
# Install a TURN server (coturn) so voice and video calls connect on mobile data
# and strict networks, and connect it to the app.
#
#   1. installs coturn (apt or dnf/yum)
#   2. writes a secure configuration (shared secret, relay ports, private networks blocked)
#   3. uses the site's SSL certificate for TURN over TLS (refreshed monthly)
#   4. opens the ports in ufw / firewalld when they are active
#   5. saves the address and shared secret in Admin → App settings → Call server (TURN)
#   6. checks that the server hands out relays with those credentials
#
# Usage (as root):
#   bash /www/wwwroot/chat.hunario.com/scripts/setup-turn.sh
#
# Optional overrides: APP_DIR, PHP_BIN, DOMAIN, WEB_USER, PUBLIC_IP, MIN_PORT, MAX_PORT
#
set -euo pipefail

APP_DIR="${APP_DIR:-/www/wwwroot/chat.hunario.com}"
PHP_BIN="${PHP_BIN:-/www/server/php/82/bin/php}"
DOMAIN="${DOMAIN:-$(basename "$APP_DIR")}"
WEB_USER="${WEB_USER:-www}"
MIN_PORT="${MIN_PORT:-49160}"
MAX_PORT="${MAX_PORT:-49400}"
ENV_FILE="$APP_DIR/.env"
CERT_SRC="/www/server/panel/vhost/cert/$DOMAIN"
CERT_DIR="/etc/coturn/certs"

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

# ---------------------------------------------------------------------------
step "Installing coturn"
if command -v turnserver >/dev/null 2>&1; then
    ok "coturn is already installed"
elif command -v apt-get >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq coturn
    ok "Installed with apt"
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
    PM=$(command -v dnf || command -v yum)
    "$PM" install -y -q epel-release || true
    "$PM" install -y -q coturn
    ok "Installed with $(basename "$PM")"
else
    die "No apt, dnf or yum found. Install coturn manually (guide step 19)."
fi

# ---------------------------------------------------------------------------
step "Network"
PUBLIC_IP="${PUBLIC_IP:-$(curl -4 -fsS --max-time 8 https://api.ipify.org || curl -4 -fsS --max-time 8 https://ifconfig.me || true)}"
[ -n "$PUBLIC_IP" ] || die "Could not detect the public IPv4 address. Run again with PUBLIC_IP=x.x.x.x"
LOCAL_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i < NF; i++) if ($i == "src") print $(i + 1)}' | head -n 1 || true)

if [ -n "$LOCAL_IP" ] && [ "$LOCAL_IP" != "$PUBLIC_IP" ]; then
    EXTERNAL_IP="$PUBLIC_IP/$LOCAL_IP"   # server behind NAT (cloud private network)
else
    EXTERNAL_IP="$PUBLIC_IP"
fi
ok "Public IP $PUBLIC_IP${LOCAL_IP:+ (local $LOCAL_IP)}"

DOMAIN_IP=$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk 'NR == 1 {print $1}' || true)
if [ -n "$DOMAIN_IP" ] && [ "$DOMAIN_IP" != "$PUBLIC_IP" ]; then
    warn "$DOMAIN resolves to $DOMAIN_IP, not $PUBLIC_IP. Behind a proxy/CDN (e.g. Cloudflare orange cloud) TURN must use the server IP."
    TURN_HOST="$PUBLIC_IP"
else
    TURN_HOST="$DOMAIN"
fi

# ---------------------------------------------------------------------------
step "Configuration"
if [ -d /etc/coturn ] && [ ! -f /etc/turnserver.conf ]; then
    CONF=/etc/coturn/turnserver.conf
else
    CONF=/etc/turnserver.conf
fi

# Running it again keeps the secret, so calls in progress keep working.
SECRET=""
[ -f "$CONF" ] && SECRET="$({ grep -E '^static-auth-secret=' "$CONF" || true; } | tail -n 1 | cut -d= -f2-)"
[ -n "$SECRET" ] || SECRET="$(env_get CHAT_CALL_TURN_SECRET)"
if [ -n "$SECRET" ]; then
    ok "Keeping the existing shared secret"
else
    SECRET="$(openssl rand -hex 32)"
    ok "New shared secret made"
fi
[ -f "$CONF" ] && cp "$CONF" "$CONF.bak.$(date +%Y%m%d%H%M%S)"

TURN_USER=$(id -u turnserver >/dev/null 2>&1 && echo turnserver || (id -u coturn >/dev/null 2>&1 && echo coturn) || echo root)

TLS=0
if [ -f "$CERT_SRC/fullchain.pem" ] && [ -f "$CERT_SRC/privkey.pem" ] && [ "$TURN_HOST" = "$DOMAIN" ]; then
    mkdir -p "$CERT_DIR"
    cat > /etc/cron.monthly/one2one-coturn-certs <<CRON
#!/bin/sh
# Copy the renewed site certificate for coturn (TURN over TLS).
cp "$CERT_SRC/fullchain.pem" "$CERT_SRC/privkey.pem" "$CERT_DIR/" && chown -R $TURN_USER "$CERT_DIR" && chmod 600 "$CERT_DIR/privkey.pem" && systemctl restart coturn
CRON
    chmod +x /etc/cron.monthly/one2one-coturn-certs
    cp "$CERT_SRC/fullchain.pem" "$CERT_SRC/privkey.pem" "$CERT_DIR/"
    chown -R "$TURN_USER" "$CERT_DIR"
    chmod 600 "$CERT_DIR/privkey.pem"
    TLS=1
    ok "TURN over TLS with the certificate of $DOMAIN"
else
    warn "No SSL certificate found in $CERT_SRC: TURN over TLS (port 5349) skipped."
fi

cat > "$CONF" <<CONF
# One2One Chat TURN server (written by scripts/setup-turn.sh)
listening-port=3478
$( [ "$TLS" = 1 ] && echo "tls-listening-port=5349" )
min-port=$MIN_PORT
max-port=$MAX_PORT
external-ip=$EXTERNAL_IP

realm=$DOMAIN
server-name=$DOMAIN
use-auth-secret
static-auth-secret=$SECRET
fingerprint

$( [ "$TLS" = 1 ] && printf 'cert=%s/fullchain.pem\npkey=%s/privkey.pem\nno-tlsv1\nno-tlsv1_1\n' "$CERT_DIR" "$CERT_DIR" )

# Never relay into this server or private networks
no-multicast-peers
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=::1
denied-peer-ip=fc00::-fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
denied-peer-ip=fe80::-febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff

no-cli
total-quota=200
stale-nonce=600
simple-log
log-file=/var/log/turnserver.log
CONF
chmod 640 "$CONF"
chown "root:$TURN_USER" "$CONF" 2>/dev/null || true
ok "Written $CONF"

[ -f /etc/default/coturn ] && sed -i -E 's/^#?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
touch /var/log/turnserver.log && chown "$TURN_USER" /var/log/turnserver.log 2>/dev/null || true

systemctl enable coturn >/dev/null 2>&1 || true
systemctl restart coturn
sleep 2
systemctl is-active --quiet coturn || { systemctl status coturn --no-pager || true; die "coturn did not start — see the log above."; }
ok "coturn is running"

# ---------------------------------------------------------------------------
step "Firewall"
PORTS_TCP="3478"
PORTS_UDP="3478"
[ "$TLS" = 1 ] && PORTS_TCP="$PORTS_TCP 5349" && PORTS_UDP="$PORTS_UDP 5349"

if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    for p in $PORTS_TCP; do ufw allow "$p/tcp" >/dev/null; done
    for p in $PORTS_UDP; do ufw allow "$p/udp" >/dev/null; done
    ufw allow "$MIN_PORT:$MAX_PORT/udp" >/dev/null
    ok "Opened in ufw"
elif command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    for p in $PORTS_TCP; do firewall-cmd --permanent --add-port="$p/tcp" >/dev/null; done
    for p in $PORTS_UDP; do firewall-cmd --permanent --add-port="$p/udp" >/dev/null; done
    firewall-cmd --permanent --add-port="$MIN_PORT-$MAX_PORT/udp" >/dev/null
    firewall-cmd --reload >/dev/null
    ok "Opened in firewalld"
else
    warn "No active ufw/firewalld found."
fi
warn "Also allow these ports in your hosting provider's firewall (VPS panel / security group), if it has one:"
printf '       TCP+UDP 3478%s, UDP %s-%s\n' "$( [ "$TLS" = 1 ] && echo ', TCP+UDP 5349')" "$MIN_PORT" "$MAX_PORT"

# ---------------------------------------------------------------------------
step "Connecting the app"
URLS="turn:$TURN_HOST:3478?transport=udp,turn:$TURN_HOST:3478?transport=tcp"
[ "$TLS" = 1 ] && URLS="$URLS,turns:$DOMAIN:5349?transport=tcp"

cd "$APP_DIR"
# Saved in Admin → App settings → Call server (TURN); the secret goes in through stdin, never the command line.
printf '%s' "$SECRET" | runuser -u "$WEB_USER" -- "$PHP_BIN" artisan chat:turn-server --urls="$URLS" --secret-stdin \
    || die "Could not save the TURN settings in the app (see the message above)."

# ---------------------------------------------------------------------------
step "Checking (asks the TURN server for a relay, like a phone in a call)"
sleep 1
if runuser -u "$WEB_USER" -- "$PHP_BIN" artisan chat:turn-server --check; then
    ok "coturn answers and the secret matches (checked from this server)"
    warn "This check does not pass through your hosting provider's firewall. Confirm the ports from outside:"
    warn "Admin → App settings → Call server (TURN) → Test from this browser, on a phone with Wi-Fi off."
else
    warn "The check failed on the server itself: coturn is not running or refused the relay"
    warn "(systemctl status coturn; tail -n 50 /var/log/turnserver.log), or this server's own firewall blocks it."
    warn "Check again later in Admin → App settings → Call server (TURN)."
fi

cat <<DONE

Done. Admin → App settings → Call server (TURN) now shows this server.
Test a call between a phone on mobile data (Wi-Fi off) and another device.
While a call is relayed, "tail -f /var/log/turnserver.log" shows the session.
DONE
