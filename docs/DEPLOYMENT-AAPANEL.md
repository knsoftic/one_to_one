# Deploying One2One Chat on aaPanel

**Domain:** `chat.hunario.com`
**Site root:** `/www/wwwroot/chat.hunario.com`
**Repository:** `https://github.com/knsoftic/one_to_one.git`

This guide deploys the Laravel application with **Nginx + PHP-FPM + MySQL** on aaPanel, **Laravel Reverb** (WebSockets) behind Nginx on `wss://chat.hunario.com`, **Supervisor** to keep Reverb running, and **Cron** for the scheduler.

```
Browser ──HTTPS──▶ Nginx (443) ──▶ PHP-FPM ──▶ Laravel (/public)
Browser ──WSS────▶ Nginx /app  ──▶ Reverb 127.0.0.1:8080   (kept alive by Supervisor)
Laravel ──HTTPS──▶ Nginx /apps ──▶ Reverb 127.0.0.1:8080   (broadcast API)
Cron (every minute) ──▶ php artisan schedule:run
```

> This guide uses **PHP 8.2**. Throughout, `php` means the aaPanel PHP 8.2 binary: `/www/server/php/82/bin/php`.

---

## Contents

1. [Server requirements](#1-server-requirements)
2. [Install software from the aaPanel App Store](#2-install-software-from-the-aapanel-app-store)
3. [Configure PHP](#3-configure-php)
4. [DNS](#4-dns)
5. [Create the website and database](#5-create-the-website-and-database)
6. [Get the code on the server](#6-get-the-code-on-the-server)
7. [Configure `.env`](#7-configure-env)
8. [Install dependencies, migrate and build](#8-install-dependencies-migrate-and-build)
9. [Website settings: running directory, rewrite, SSL](#9-website-settings-running-directory-rewrite-ssl)
10. [Nginx WebSocket proxy for Reverb](#10-nginx-websocket-proxy-for-reverb)
11. [Supervisor: keep Reverb running](#11-supervisor-keep-reverb-running)
12. [Cron: Laravel scheduler](#12-cron-laravel-scheduler)
13. [File permissions](#13-file-permissions)
14. [Verify the deployment](#14-verify-the-deployment)
15. [Deploying updates](#15-deploying-updates)
16. [Security checklist](#16-security-checklist)
17. [Troubleshooting](#17-troubleshooting)
18. [Android app push notifications](#18-android-app-push-notifications)
19. [Voice & video calls (TURN server)](#19-voice--video-calls-turn-server)

---

## 1. Server requirements

| Item | Recommended |
|------|-------------|
| OS | Ubuntu 22.04 / 24.04 or Debian 12 (CentOS/AlmaLinux also work) |
| RAM | 2 GB minimum (building assets with npm needs memory) |
| Web server | **Nginx** 1.22+ (recommended over Apache for WebSockets) |
| PHP | **8.2** (the version this project was built and tested on) |
| Database | **MySQL 8.0** (or MariaDB 10.6+) |
| Node.js | 20 LTS or 22 LTS (only needed to build assets) |
| Composer | 2.x |
| Ports open to the internet | 80, 443 (and your SSH / aaPanel ports). **Do not** open 8080. |

---

## 2. Install software from the aaPanel App Store

In **aaPanel → App Store**, install:

1. **Nginx** (latest stable)
2. **MySQL 8.0**
3. **PHP-8.2**
4. **Supervisor manager** (search "Supervisor")
5. **Node.js version manager** → install Node **20.x** or **22.x** and set it as the command-line version
6. *(Optional)* **Redis** + the PHP `redis` extension if you want Redis for cache/sessions

Install **Composer** (via SSH as root) if `composer -V` is not available:

```bash
cd /tmp
curl -sS https://getcomposer.org/installer | /www/server/php/82/bin/php
mv composer.phar /usr/local/bin/composer
composer -V
```

Check that the correct PHP is used on the command line:

```bash
/www/server/php/82/bin/php -v
node -v
npm -v
```

---

## 3. Configure PHP

**aaPanel → App Store → PHP-8.2 → Settings**

### 3.1 Install extensions (Install extensions tab)

| Extension | Needed for |
|-----------|-----------|
| **fileinfo** | Upload content-type validation (**required** — not installed by default on aaPanel) |
| **exif** | Correct photo orientation |
| **opcache** | Performance |
| redis *(optional)* | Redis cache/sessions |

`gd` (with JPEG/PNG/WebP), `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip` are normally already compiled in. Verify:

```bash
/www/server/php/82/bin/php -m | grep -Ei "fileinfo|exif|gd|pdo_mysql|mbstring|openssl|curl|zip|opcache"
```

### 3.2 Disabled functions (Disabled functions tab)

aaPanel disables several functions by default. **Remove these from the list:**

```
putenv
proc_open
proc_get_status
pcntl_signal
pcntl_alarm
pcntl_async_signals
```

(`proc_open` is needed by Composer and Artisan; the `pcntl_*` functions let Reverb and queue workers shut down gracefully.)

### 3.3 Configuration file values (Configuration modification tab)

| Setting | Value |
|---------|-------|
| `upload_max_filesize` | `20M` |
| `post_max_size` | `25M` |
| `memory_limit` | `256M` (512M recommended — image processing) |
| `max_execution_time` | `60` |

Click **Save** and **Restart PHP**.

---

## 4. DNS

At your DNS provider, create an **A record**:

| Type | Name | Value |
|------|------|-------|
| A | `chat` | `<your server IP>` |

If the domain uses **Cloudflare**: set SSL/TLS mode to **Full (strict)** and keep **Network → WebSockets** enabled. (Proxied/orange cloud works with WebSockets on port 443.)

Wait until `ping chat.hunario.com` resolves to your server.

---

## 5. Create the website and database

### 5.1 Website

**aaPanel → Website → Add site**

| Field | Value |
|-------|-------|
| Domain name | `chat.hunario.com` |
| Root directory | `/www/wwwroot/chat.hunario.com` |
| FTP | Do not create |
| Database | **MySQL**, create it here (or in 5.2) |
| PHP version | **PHP-82** |

### 5.2 Database

If not created with the site: **aaPanel → Database → Add database**

| Field | Value |
|-------|-------|
| Database name | `chat_hunario` |
| Username | `chat_hunario` |
| Password | a strong random password (copy it) |
| Access permission | Local server |
| Charset | **utf8mb4** |

---

## 6. Get the code on the server

aaPanel creates a few default files in the site root. Remove them (the `.user.ini` file is protected — leave it):

```bash
cd /www/wwwroot/chat.hunario.com
rm -f index.html 404.html .htaccess .htaccess.bak
ls -la
```

aaPanel creates the site folder owned by the `www` user, while these commands run as `root`. Git refuses to work in a folder owned by another user ("detected dubious ownership"), so first mark the folder as safe:

```bash
git config --global --add safe.directory /www/wwwroot/chat.hunario.com
```

Clone the repository **into the existing folder**:

```bash
cd /www/wwwroot/chat.hunario.com
git init -b main
git remote add origin https://github.com/knsoftic/one_to_one.git 2>/dev/null || git remote set-url origin https://github.com/knsoftic/one_to_one.git
git fetch origin main
git checkout -f -B main origin/main
git log --oneline -1
```

> If the repository is **private**, use a GitHub personal access token or a deploy key:
> `git remote set-url origin https://<TOKEN>@github.com/knsoftic/one_to_one.git`

---

## 7. Configure `.env`

```bash
cd /www/wwwroot/chat.hunario.com
cp .env.example .env
nano .env
```

Set the following values (keep the other defaults from `.env.example`):

```dotenv
APP_NAME="One2One Chat"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_TIMEZONE=UTC
APP_URL=https://chat.hunario.com

LOG_CHANNEL=daily
LOG_LEVEL=error

# --- Database (from step 5.2) ---
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chat_hunario
DB_USERNAME=chat_hunario
DB_PASSWORD=YOUR_DB_PASSWORD

# --- Sessions (HTTPS only) ---
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null

BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

# --- Mail (required for "Forgot password") ---
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS="no-reply@hunario.com"
MAIL_FROM_NAME="${APP_NAME}"

# --- Laravel Reverb ---
# Public address used by browsers AND by Laravel (through Nginx on 443)
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=chat.hunario.com
REVERB_PORT=443
REVERB_SCHEME=https
# Reverb listens only on localhost; Nginx proxies to it
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_ALLOWED_ORIGINS=chat.hunario.com

VITE_APP_NAME="${APP_NAME}"
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# --- Chat settings ---
CHAT_CSP=true

# --- First administrator (used once by the seeder) ---
ADMIN_NAME="Administrator"
ADMIN_USERNAME=admin
ADMIN_EMAIL=admin@hunario.com
ADMIN_PHONE=+920000000000
ADMIN_PASSWORD=ChangeMe-Strong-Password-123
```

Generate the Reverb credentials (paste the output into `.env`):

```bash
echo "REVERB_APP_ID=$(shuf -i 100000-999999 -n 1)"
echo "REVERB_APP_KEY=$(openssl rand -hex 16)"
echo "REVERB_APP_SECRET=$(openssl rand -hex 32)"
```

Protect the file:

```bash
chmod 640 .env
```

---

## 8. Install dependencies, migrate and build

Run as root from the site root (permissions are fixed in step 13):

```bash
cd /www/wwwroot/chat.hunario.com
export COMPOSER_ALLOW_SUPERUSER=1
PHP=/www/server/php/82/bin/php

# PHP dependencies (production)
$PHP /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction

# Application key
$PHP artisan key:generate --force

# Database tables + first administrator (demo users are NOT created in production)
$PHP artisan migrate --force --seed

# Public storage link for profile pictures (symlink() is disabled in PHP, so use ln)
ln -sfn /www/wwwroot/chat.hunario.com/storage/app/public /www/wwwroot/chat.hunario.com/public/storage

# Frontend assets
npm ci
npm run build

# Production caches
$PHP artisan optimize
$PHP artisan view:cache
```

After the admin account is created you may clear `ADMIN_PASSWORD` in `.env` (it is only used when the admin is first created), then run `$PHP artisan config:cache`.

> Promote any other existing account to admin later with:
> `$PHP artisan chat:make-admin someone@example.com`

---

## 9. Website settings: running directory, rewrite, SSL

**aaPanel → Website → chat.hunario.com → Settings (Conf)**

### 9.1 Site directory

- **Running directory:** `/public` → **Save**
- **Anti-XSS attack (open_basedir):** can stay enabled. If you see `open_basedir restriction` errors, disable it.

### 9.2 URL rewrite

Select the **laravel5** template, or paste:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

→ **Save**

### 9.3 SSL

- **SSL → Let's Encrypt** → select `chat.hunario.com` → **Apply**
- Enable **Force HTTPS**

HTTPS is required for secure cookies, WebSockets (`wss://`) and microphone access (voice notes).

---

## 10. Nginx WebSocket proxy for Reverb

**aaPanel → Website → chat.hunario.com → Settings → Config file**

Inside the `server { ... }` block, add the following **just below the line `#SSL-END`** (before the other `location` blocks), then **Save**:

```nginx
    # Let Laravel send its own 404 responses (aaPanel intercepts them by default,
    # which replaces JSON errors and the app's error pages with nginx's page)
    fastcgi_intercept_errors off;

    # ---------- Laravel Reverb (WebSockets) ----------
    # Broadcast API used by Laravel (plain HTTP to Reverb)
    location ^~ /apps/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # WebSocket connections from browsers (wss://chat.hunario.com/app/...)
    location ^~ /app/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
        proxy_buffering off;
    }
    # -------------------------------------------------
```

Also make sure uploads are allowed (in the same `server` block or in **App Store → Nginx → Settings → Configuration modification**):

```nginx
client_max_body_size 25m;
```

Test and reload:

```bash
nginx -t && nginx -s reload
```

<details>
<summary>Using Apache instead of Nginx</summary>

Enable `mod_proxy`, `mod_proxy_http` and `mod_proxy_wstunnel`, set the running directory to `/public` (the included `public/.htaccess` handles rewrites) and add to the site's VirtualHost (443):

```apache
ProxyPreserveHost On
RewriteEngine On
RewriteCond %{HTTP:Upgrade} websocket [NC]
RewriteCond %{HTTP:Connection} upgrade [NC]
RewriteRule ^/app/(.*) ws://127.0.0.1:8080/app/$1 [P,L]
ProxyPass /apps/ http://127.0.0.1:8080/apps/
ProxyPassReverse /apps/ http://127.0.0.1:8080/apps/
```
</details>

---

## 11. Supervisor: keep Reverb running

**aaPanel → App Store → Supervisor manager → Settings → Add Daemon**

| Field | Value |
|-------|-------|
| Name | `chat-reverb` |
| Run user | `www` |
| Run directory | `/www/wwwroot/chat.hunario.com` |
| Start command | `/www/server/php/82/bin/php artisan reverb:start --host=127.0.0.1 --port=8080` |
| Processes | `1` |

**Confirm**, then check that its status is **Running**.

*(Optional, recommended for future background jobs)* add a second daemon:

| Field | Value |
|-------|-------|
| Name | `chat-queue` |
| Run user | `www` |
| Run directory | `/www/wwwroot/chat.hunario.com` |
| Start command | `/www/server/php/82/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600` |
| Processes | `1` |

Check from SSH:

```bash
ss -ltnp | grep 8080          # Reverb must listen on 127.0.0.1:8080
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/   # any HTTP code means it is up
```

> For many simultaneous users raise the open-files limit for the Reverb process (e.g. `ulimit -n 10000` / `minfds` in Supervisor).

---

## 12. Cron: Laravel scheduler

The scheduler marks inactive users offline every minute.

**aaPanel → Cron → Add Task**

| Field | Value |
|-------|-------|
| Type of Task | Shell Script |
| Name | `chat-scheduler` |
| Execution cycle | **N Minutes → 1** |
| Script content | *(below)* |

```bash
su -s /bin/bash www -c "cd /www/wwwroot/chat.hunario.com && /www/server/php/82/bin/php artisan schedule:run >> /dev/null 2>&1"
```

(Running as `www` prevents root-owned log/cache files that would cause 500 errors.)

---

## 13. File permissions

```bash
cd /www/wwwroot/chat.hunario.com
chown -R www:www /www/wwwroot/chat.hunario.com
find . -path ./node_modules -prune -o -path ./vendor -prune -o -type d -exec chmod 755 {} \;
chmod -R 775 storage bootstrap/cache
chmod 640 .env
```

A message like `chown: changing ownership of '.user.ini': Operation not permitted` is normal and can be ignored.

---

## 14. Verify the deployment

1. Open **https://chat.hunario.com** → you are redirected to the sign-in page.
2. Sign in with the `ADMIN_EMAIL` / `ADMIN_PASSWORD` account → open **/admin**.
3. Register two test accounts (two different browsers) and send messages between them.
4. In the chat sidebar, hover the green **Online** status: it should say **"Live updates via WebSocket"**. Browser DevTools → Network → WS should show `wss://chat.hunario.com/app/...` with status **101**.
5. Test: typing indicator, ✓✓ / blue ✓✓, image upload, voice note (HTTPS), notifications, block/unblock.
6. Check headers:

   ```bash
   curl -sI https://chat.hunario.com/login | grep -Ei "strict-transport|content-security|x-frame|x-content-type"
   ```

7. Check logs:

   ```bash
   tail -n 50 /www/wwwroot/chat.hunario.com/storage/logs/laravel-$(date +%F).log
   ```

---

## 15. Deploying updates

A ready-made script is included at [`scripts/deploy.sh`](../scripts/deploy.sh). On the server:

```bash
cd /www/wwwroot/chat.hunario.com
chmod +x scripts/deploy.sh
PHP_BIN=/www/server/php/82/bin/php ./scripts/deploy.sh
```

It puts the site in maintenance mode, pulls `main`, installs dependencies, runs migrations, rebuilds assets and caches, restarts Reverb and queue workers, fixes permissions and brings the site back up.

After changing `.env` at any time:

```bash
cd /www/wwwroot/chat.hunario.com
/www/server/php/82/bin/php artisan config:cache
/www/server/php/82/bin/php artisan reverb:restart
```

---

## 16. Security checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] HTTPS with **Force HTTPS**; `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`
- [ ] `REVERB_SERVER_HOST=127.0.0.1` and port **8080 not open** in the aaPanel firewall or cloud security group
- [ ] `REVERB_ALLOWED_ORIGINS=chat.hunario.com`
- [ ] Strong database password; database access limited to the local server
- [ ] Strong admin password (and `ADMIN_PASSWORD` cleared from `.env` afterwards)
- [ ] `.env` is `640` and not web-accessible (running directory is `/public`)
- [ ] `storage/app/private/chat` stays private (never symlinked into `public`)
- [ ] aaPanel itself: change the default panel port/entrance, enable 2FA, keep the panel and system updated
- [ ] Backups: **aaPanel → Cron → Backup database** (daily) and back up `/www/wwwroot/chat.hunario.com/storage/app` (uploads and avatars)
- [ ] Optional: enable aaPanel's Nginx firewall/WAF, fail2ban for SSH
- [ ] TURN (step 19): long random `static-auth-secret`, private IP ranges denied, only ports 3478, 5349 and the relay range open

---

## 17. Troubleshooting

| Problem | Fix |
|---------|-----|
| **`fatal: detected dubious ownership in repository`** | The folder is owned by `www` but git runs as root. Run `git config --global --add safe.directory /www/wwwroot/chat.hunario.com` once, then repeat the git command. |
| **Every unknown URL shows nginx's plain "404 Not Found" page** | aaPanel intercepts PHP error responses. Add `fastcgi_intercept_errors off;` to the site's Nginx config (step 10) and reload Nginx. |
| **`/app/` or `/apps/` return nginx 404** | The Reverb proxy blocks from step 10 are missing (or Nginx was not reloaded). With the proxy in place these paths answer from Reverb, not nginx. |
| **CSP header shows `ws://127.0.0.1:8080`** | `.env` still has the local Reverb values. Set `REVERB_HOST=chat.hunario.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`, then `php artisan config:cache` and `php artisan reverb:restart`. |
| **500 Server Error** | Check `storage/logs/laravel-*.log`. Usually permissions (step 13), missing `APP_KEY`, wrong DB credentials, or cached config (`php artisan optimize:clear` then `php artisan optimize`). |
| **404 on every page except /** | Running directory not set to `/public` or the Laravel rewrite is missing (step 9). |
| **Unstyled page / Vite manifest not found** | Run `npm ci && npm run build`; `public/build` is not stored in git. |
| **Sidebar shows Online but hover says "via AJAX"** (no WebSocket) | Reverb not running (Supervisor), Nginx `/app/` block missing, or `REVERB_HOST/PORT/SCHEME` wrong. After editing `.env`: `php artisan config:cache` and restart Reverb. Messages still work via polling meanwhile. |
| **WebSocket 403 / closes immediately** | `REVERB_APP_KEY` mismatch or `REVERB_ALLOWED_ORIGINS` doesn't match the domain. |
| **Broadcast errors "cURL error" in logs** | Laravel cannot reach `https://chat.hunario.com/apps/...`: add the `/apps/` Nginx block; make sure the server can resolve its own domain (`curl -I https://chat.hunario.com`). |
| **`Call to undefined function putenv()` / `proc_open()`** | Remove them from PHP *Disabled functions* (step 3.2) and restart PHP. |
| **Uploads rejected / "file type not allowed" for valid files** | Install the **fileinfo** extension (step 3.1). |
| **413 Request Entity Too Large** | Increase `client_max_body_size` (Nginx) and `upload_max_filesize` / `post_max_size` (PHP). |
| **Profile pictures don't show** | Re-create the storage link (step 8, `ln -sfn ...`). |
| **419 Page Expired** | Use `https://` (secure cookies), make sure `APP_URL` matches, clear browser cookies, `php artisan config:cache`. |
| **Microphone not available** | Voice notes and calls require HTTPS and browser microphone permission. |
| **Calls ring but never connect** | A TURN server is needed on mobile data and strict networks: see step 19. |
| **Users never show as offline** | Cron task not running (step 12). Check **aaPanel → Cron → Log**. |
| **`open_basedir restriction in effect`** | Disable *Anti-XSS attack* in the site's *Site directory* settings. |
| **Changes not visible after update** | `php artisan optimize:clear && php artisan optimize && php artisan view:cache`, rebuild assets, hard-refresh the browser. |
| **Android app: no push notifications** | Check step 18: `FCM_CREDENTIALS` readable by `www`, `php artisan config:cache` after editing `.env`, and an APK built with `google-services.json`. Look for "Push" warnings in `storage/logs`. Phones without Google Play services use the fallback connection, which needs Reverb (steps 10–11). |

---

## 18. Android app push notifications

The Android app (`mobile/`, see [`mobile/README.md`](../mobile/README.md)) opens `https://chat.hunario.com`, so
contacts sync, downloads, the back button and notifications work as soon as the latest code is deployed.

Notifications work like WhatsApp: **Firebase Cloud Messaging** (free) delivers them even when the app is closed, and the
app draws them itself — sender photo, conversation style, *Reply* and *Mark as read* buttons, ✓✓ delivered for the
sender, and the notification disappears when the chat is read on another device. Phones without Google Play services
fall back to the app's own background connection (Reverb WebSocket + polling).

### 18.1 Firebase project (once, in your Google account)

1. <https://console.firebase.google.com> → **Add project** (Google Analytics is not needed).
2. **Add app → Android**, package name **`com.hunario.chat`** → *Register app*.
3. Download **`google-services.json`** → it goes into the Android project (`mobile/android/app/`) and the APK is rebuilt.
4. ⚙ **Project settings → Service accounts → Generate new private key** → downloads the service-account JSON
   (a secret: never commit it or put it under `public/`).

### 18.2 Server

1. Upload the service-account JSON through **aaPanel → Files** to
   `/www/wwwroot/chat.hunario.com/storage/app/private/firebase-service-account.json`, then:

   ```bash
   cd /www/wwwroot/chat.hunario.com
   chown www:www storage/app/private/firebase-service-account.json
   chmod 640 storage/app/private/firebase-service-account.json
   ```

2. In `.env`:

   ```dotenv
   FCM_CREDENTIALS=storage/app/private/firebase-service-account.json
   ```

3. Apply:

   ```bash
   /www/server/php/82/bin/php artisan config:cache
   ```

The server needs outbound HTTPS to `oauth2.googleapis.com` and `fcm.googleapis.com` (allowed by default). Pushes are
sent right after the HTTP response, so no queue worker is required.

Optional `.env` settings:

```dotenv
CHAT_MOBILE_NOTIFICATION_PREVIEW=true   # false = "New message" instead of the text
CHAT_MOBILE_POLL_SECONDS=60             # fallback connection only
```

Signing out in the app, changing or resetting the password, or suspending the account stops notifications on that
phone. Tokens of uninstalled apps are removed automatically.

---

## 19. Voice & video calls (TURN server)

Calls work in the browser and in the Android app as soon as the latest code is deployed: the call buttons are in the
chat header, calls ring on every device of the person called (full-screen ringing on Android, even when the app is
closed — step 18) and each call is saved in the chat history.

Audio and video travel **directly between the two devices** (WebRTC). The server only relays the set-up messages, so
calls cost almost no server bandwidth. Google's free STUN servers let most Wi-Fi networks connect. On **mobile data**
and strict office/hotel networks a direct connection is often impossible; then the call needs a **TURN server** that
relays the media. Without one those calls stay on "Connecting…" and end with "Couldn't connect".

Install [coturn](https://github.com/coturn/coturn) on the same server (free, open source):

### 19.1 Install

```bash
apt update && apt install -y coturn        # Ubuntu / Debian
# CentOS / AlmaLinux / Rocky: dnf install -y epel-release && dnf install -y coturn
openssl rand -hex 32                       # copy this: the TURN shared secret
curl -4 -s https://ifconfig.me; echo       # the server's public IPv4
```

### 19.2 Configure

Replace `/etc/turnserver.conf` (Ubuntu/Debian) or `/etc/coturn/turnserver.conf` (CentOS family) with:

```ini
listening-port=3478
tls-listening-port=5349
min-port=49160
max-port=49200
external-ip=YOUR_PUBLIC_IP

realm=chat.hunario.com
server-name=chat.hunario.com
use-auth-secret
static-auth-secret=THE_SECRET_FROM_19.1
fingerprint

cert=/etc/coturn/certs/fullchain.pem
pkey=/etc/coturn/certs/privkey.pem
no-tlsv1
no-tlsv1_1

# Never relay into the server's own or private networks
no-multicast-peers
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
no-cli
total-quota=100
stale-nonce=600
simple-log
log-file=/var/log/turnserver.log
```

Each relayed call uses about two ports from `min-port`–`max-port`; widen the range for more simultaneous calls.

TLS (`turns:` on 5349) gets calls through networks that only allow HTTPS-like traffic. Copy the site's certificate
(aaPanel keeps it root-only) and let a monthly cron job refresh the copy after Let's Encrypt renewals:

```bash
mkdir -p /etc/coturn/certs
cp /www/server/panel/vhost/cert/chat.hunario.com/fullchain.pem /etc/coturn/certs/
cp /www/server/panel/vhost/cert/chat.hunario.com/privkey.pem /etc/coturn/certs/
chown -R turnserver:turnserver /etc/coturn/certs 2>/dev/null || chown -R coturn:coturn /etc/coturn/certs
chmod 600 /etc/coturn/certs/privkey.pem
```

Start it:

```bash
sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn 2>/dev/null
systemctl enable --now coturn
systemctl restart coturn && systemctl status coturn --no-pager
```

### 19.3 Open the ports

In **aaPanel → Security → Firewall** *and* in your cloud provider's firewall / security group:

| Port | Protocol | Purpose |
|------|----------|---------|
| 3478 | TCP + UDP | TURN |
| 5349 | TCP + UDP | TURN over TLS |
| 49160–49200 | UDP | Relayed audio/video |

### 19.4 Connect the app

In `.env` (same secret as in `turnserver.conf`):

```dotenv
CHAT_CALL_TURN_URLS="turn:chat.hunario.com:3478?transport=udp,turn:chat.hunario.com:3478?transport=tcp,turns:chat.hunario.com:5349?transport=tcp"
CHAT_CALL_TURN_SECRET=THE_SECRET_FROM_19.1
```

```bash
/www/server/php/82/bin/php artisan config:cache
```

Every user now receives TURN credentials that expire after 12 hours (`CHAT_CALL_TURN_TTL`), so a copied password is
useless. Other call settings:

```dotenv
CHAT_CALLS_ENABLED=true        # false hides the call buttons
CHAT_CALL_RING_SECONDS=45      # unanswered calls become "missed" after this
```

**Test:** make a call between a phone on **mobile data** (Wi-Fi off) and a computer. It should connect within a few
seconds. `tail -f /var/log/turnserver.log` shows `session ... new` lines while a call is being relayed.

### 19.5 Call troubleshooting

| Problem | Fix |
|---------|-----|
| Call buttons missing | `CHAT_CALLS_ENABLED=false`, or cached config: `php artisan config:cache`. |
| "Calls need a secure (https) connection" | Open the site over `https://`. Browsers only allow camera and microphone on HTTPS. |
| "Allow microphone access to make calls" | The browser or phone blocked the microphone/camera. Allow it in the site settings (lock icon) or in Android → Apps → One2One Chat → Permissions. |
| Rings, but stays on "Connecting…" / "Couldn't connect" | No TURN server, wrong secret, ports closed, or `external-ip` wrong. Check steps 19.2–19.4 and `/var/log/turnserver.log`. |
| The other person never rings | Reverb/polling not working (steps 10–11), or on Android: push not configured (step 18). The ringing screen needs *Display over lock screen / full-screen notifications* allowed on Android 14+. |
| Calls stay "ringing" after a crash | The scheduler closes abandoned calls every minute (step 12 cron). |
