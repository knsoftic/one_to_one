# One2One Chat

A modern, secure, real-time **one-to-one chat** web application built with **Laravel 12, MySQL, Blade, Tailwind CSS, vanilla JavaScript (AJAX) and Laravel Reverb (WebSockets)**.

- Private conversations between exactly two people, delivered instantly
- Text, emoji, images, PDF/DOC/DOCX documents and voice notes
- Reply, edit, copy, delete for me / delete for everyone
- ✓ sent · ✓✓ delivered · blue ✓✓ seen receipts, typing indicator, online status & "last seen"
- Recent chats with unread badges, user search, block / unblock
- **Phone contacts like WhatsApp**: sync contacts from the phone (Android Chrome) or import a `.vcf` file to see who is registered, shown with the names you saved
- Quick sign-up (profile photo is an optional step after registration)
- In-app, desktop and sound notifications with a notification centre
- Light, dark and system themes; fully responsive (desktop, tablet, mobile)
- Admin panel (statistics and account management — **no access to private chats**)
- Automatic AJAX polling fallback when WebSockets are unavailable

See [`development-progress.md`](development-progress.md) for the phase-by-phase implementation log.

**Deploying on aaPanel?** Follow the step-by-step guide: [`docs/DEPLOYMENT-AAPANEL.md`](docs/DEPLOYMENT-AAPANEL.md) (chat.hunario.com).

**Android app:** a Capacitor app in [`mobile/`](mobile/README.md) wraps the live site and adds full phone-book contact matching, WhatsApp-style push notifications (Firebase, with Reply / Mark as read and a fallback for phones without Google services), native downloads and the Android back button.

---

## Table of contents

1. [Requirements](#1-requirements)
2. [Installation](#2-installation)
3. [Running the application](#3-running-the-application)
4. [Demo accounts](#4-demo-accounts)
5. [WebSocket / broadcasting configuration](#5-websocket--broadcasting-configuration)
6. [Running on XAMPP (Apache)](#6-running-on-xampp-apache)
7. [Production deployment](#7-production-deployment)
8. [Testing](#8-testing)
9. [Architecture](#9-architecture)
10. [Security](#10-security)
11. [Configuration reference](#11-configuration-reference)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Requirements

| Software | Version |
|----------|---------|
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` (with JPEG, PNG, WebP), `zip`, `curl`, `exif` (recommended) |
| Composer | 2.x |
| MySQL / MariaDB | MySQL 8.0+ or MariaDB 10.4+ |
| Node.js / npm | Node 20+ |

PHP upload limits must allow the configured attachment sizes (default 10 MB): `upload_max_filesize` and `post_max_size` ≥ 12M.

---

## 2. Installation

### 2.1 Install PHP dependencies

```bash
composer install
```

### 2.2 Environment file

```bash
cp .env.example .env
```

On Windows (PowerShell): `Copy-Item .env.example .env`

### 2.3 Generate the application key

```bash
php artisan key:generate
```

### 2.4 Create the MySQL database

```sql
CREATE DATABASE one_to_one_chat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then set the connection in `.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=one_to_one_chat
DB_USERNAME=root
DB_PASSWORD=
```

### 2.5 Generate Reverb (WebSocket) credentials

```bash
php artisan reverb:install
```

This fills `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env`. You can also set them manually to long random strings. See [section 5](#5-websocket--broadcasting-configuration).

### 2.6 Set the first administrator (optional)

Edit the `ADMIN_*` values in `.env` (leave `ADMIN_PASSWORD` empty to get a random password printed by the seeder).

### 2.7 Run migrations and seeders

```bash
php artisan migrate --seed
```

- `AdminSeeder` creates/updates the administrator from `ADMIN_*`.
- `DemoSeeder` (non-production only) creates demo users with sample conversations.

Use `php artisan migrate` without `--seed` for an empty installation, then promote an existing account with:

```bash
php artisan chat:make-admin you@example.com
```

### 2.8 Link public storage (profile pictures)

```bash
php artisan storage:link
```

Chat attachments are stored privately in `storage/app/private/chat` and are **never** exposed through this link.

### 2.9 Install and build frontend assets

```bash
npm install
npm run build
```

During development you can use hot reloading instead:

```bash
npm run dev
```

---

## 3. Running the application

Open three terminals:

```bash
# 1) Web server (http://127.0.0.1:8000)
php artisan serve

# 2) WebSocket server (Laravel Reverb)
php artisan reverb:start

# 3) Scheduler (marks inactive users offline every minute)
php artisan schedule:work
```

Optional (not required — broadcasts and notifications are sent synchronously):

```bash
php artisan queue:work
```

Visit **http://127.0.0.1:8000**, register an account or sign in with a demo account.

> Make sure `APP_URL` matches the address you use in the browser.
> If Reverb is not running, the app keeps working through AJAX polling (the sidebar still shows **Online**; hovering it explains the mode).

---

## 4. Demo accounts

Created by `php artisan migrate --seed` in non-production environments:

| Name | Email | Username | Password |
|------|-------|----------|----------|
| Awais Ahmed | awais@example.com | awais | `Password1` |
| Ahmed Khan | ahmed@example.com | ahmed | `Password1` |
| Ali Raza | ali@example.com | ali | `Password1` |
| Sara Malik | sara@example.com | sara | `Password1` |
| Fatima Noor | fatima@example.com | fatima | `Password1` |
| Usman Tariq | usman@example.com | usman | `Password1` |
| Administrator | value of `ADMIN_EMAIL` | value of `ADMIN_USERNAME` | value of `ADMIN_PASSWORD` |

You can sign in with **email, username or mobile number**. Open two different browsers (or a normal and a private window) to chat between two accounts in real time.

---

## 5. WebSocket / broadcasting configuration

The app uses **Laravel Reverb** (a first-party WebSocket server speaking the Pusher protocol) and **Laravel Echo** in the browser.

```dotenv
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...

# Address used by the browser and the Laravel app to reach Reverb
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http

# Address the Reverb process binds to
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Restrict in production
REVERB_ALLOWED_ORIGINS=chat.example.com
```

How it works:

- Channels (`routes/channels.php`): private `App.Models.User.{id}` (only that user) and presence channel `online`. Channel authorization requires an authenticated, active account.
- Events: `MessageSent`, `MessageUpdated`, `MessageHidden`, `MessagesStatusUpdated`, `UserTyping`, `UserPresenceChanged`, `BlockStatusChanged` and the `NewMessageNotification` broadcast.
- The browser connection settings are delivered at runtime from the server config, so changing `REVERB_*` does **not** require rebuilding assets (restart `reverb:start` and reload the page).
- **Resilience:** if Reverb is down, sending still works (broadcast failures are logged and briefly paused), and clients switch to AJAX polling (`GET /chat/sync`) until the socket reconnects, then catch up automatically.

After changing `.env`, clear cached configuration if you cached it: `php artisan config:clear`.

---

## 6. Running on XAMPP (Apache)

The project folder name contains spaces (`one to one`), so serve it through a virtual host that points at the `public` directory.

1. Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

   ```apache
   <VirtualHost *:80>
       ServerName one2one.test
       DocumentRoot "C:/xampp/htdocs/one to one/public"
       <Directory "C:/xampp/htdocs/one to one/public">
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

2. Add `127.0.0.1 one2one.test` to `C:\Windows\System32\drivers\etc\hosts` (as administrator).
3. Make sure `mod_rewrite` is enabled, restart Apache, and set `APP_URL=http://one2one.test`.
4. Start Reverb and the scheduler from a terminal in the project folder (`php artisan reverb:start`, `php artisan schedule:work`).

Alternatively just use `php artisan serve` as described in section 3.

---

## 7. Production deployment

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan db:seed --class=AdminSeeder --force   # first deployment only
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

`.env` checklist:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://chat.example.com
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
REVERB_HOST=chat.example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_ALLOWED_ORIGINS=chat.example.com
MAIL_MAILER=smtp   # plus real SMTP credentials for password reset emails
```

Keep long-running processes alive with Supervisor (or systemd):

```ini
[program:one2one-reverb]
command=php /var/www/one2one/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/one2one/storage/logs/reverb.log
```

Cron entry for the scheduler:

```cron
* * * * * cd /var/www/one2one && php artisan schedule:run >> /dev/null 2>&1
```

Nginx: proxy WebSocket traffic to Reverb (the browser connects to `wss://chat.example.com/app/...`):

```nginx
location /app {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_pass http://127.0.0.1:8080;
}
location /apps {
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_pass http://127.0.0.1:8080;
}
```

Other recommendations: serve over HTTPS (required for microphone access on non-localhost hosts), make `storage/` and `bootstrap/cache/` writable by the web server only, back up the database and `storage/app/private/chat`.

---

## 8. Testing

Tests run against a separate MySQL database (configured in `phpunit.xml`):

```sql
CREATE DATABASE one_to_one_chat_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan test
```

The suite (151 tests) covers authentication, profiles, conversations, messaging, realtime events, receipts, presence, polling sync, attachments (with real content-type detection), message actions, notifications, blocking, phone contacts, mobile app device notifications, the admin panel and security headers.

Code style: `vendor/bin/pint`

---

## 9. Architecture

```
app/
├── Broadcasting/ResilientBroadcastManager.php  # broadcasting never breaks requests
├── Events/            # MessageSent, MessageUpdated, MessageHidden, MessagesStatusUpdated,
│                      # UserTyping, UserPresenceChanged, BlockStatusChanged
├── Listeners/         # SendNewMessageNotification
├── Notifications/     # NewMessageNotification (database + broadcast)
├── Http/
│   ├── Controllers/   # Auth\AuthController, Auth\PasswordResetController, ChatController,
│   │                  # ConversationController, MessageController, MessageStatusController,
│   │                  # AttachmentController, PresenceController, SyncController,
│   │                  # UserController, BlockController, NotificationController,
│   │                  # ProfileController, AdminController
│   ├── Middleware/    # EnsureAccountIsActive, EnsureUserIsAdmin, TrackUserActivity, SecurityHeaders
│   ├── Requests/      # Form Requests (auth, profile, chat, admin)
│   └── Resources/     # UserResource, ConversationResource, MessageResource
├── Models/            # User, Conversation, Message, BlockedUser
├── Policies/          # ConversationPolicy, MessagePolicy, UserPolicy
├── Services/          # AccountService, ConversationService, MessageService, AttachmentService,
│                      # ImageService, PresenceService, TypingService, SyncService,
│                      # BlockService, AdminService
└── View/Composers/    # App & chat frontend configuration
resources/
├── views/             # Blade layouts, auth, chat, profile, admin, errors
├── css/               # theme tokens, components, auth, chat, settings, admin
└── js/chat/           # ChatApp, realtime (Echo + polling), templates, actions, attachments,
                       # voice, lightbox, emoji, notifications, blocks
```

### Database

| Table | Purpose |
|-------|---------|
| `users` | Accounts: unique username / email / phone, profile image, presence (`is_online`, `last_seen`), role, status, preferences |
| `conversations` | `user_one_id` < `user_two_id` (unique pair → no duplicates), `last_message_id` |
| `messages` | Content, type, private attachment metadata, reply, edit flags, per-side and global deletion, `sent_at` / `delivered_at` / `seen_at` |
| `blocked_users` | `user_id` blocked `blocked_user_id` |
| `notifications` | Laravel database notifications (notification centre) |

### Main routes

| Method & URI | Description |
|--------------|-------------|
| `GET /login`, `/register`, `/forgot-password`, `/reset-password/{token}` | Guest pages |
| `GET /chat`, `GET /chat/{conversation}` | Chat dashboard |
| `GET/POST /conversations` · `GET /conversations/{id}` | Recent chats · start chat · details |
| `GET/POST /conversations/{id}/messages` | History (cursor pagination) · send text / file / voice |
| `PATCH/DELETE /messages/{id}` · `GET /messages/{id}/attachment` | Edit · delete · private download |
| `POST /conversations/{id}/seen` · `POST /messages/delivered` | Read / delivery receipts |
| `POST /conversations/{id}/typing` · `POST /presence/heartbeat` · `POST /presence/offline` · `GET /chat/sync` | Realtime helpers / polling |
| `GET /users/search` · `GET /users/online` · `POST/DELETE /users/{id}/block` | Users & blocking |
| `GET /notifications` · `POST /notifications/read` | Notification centre |
| `GET /settings` · `PUT /settings/profile` · `PUT /settings/password` · `PATCH /settings/preferences` | Profile settings |
| `GET /admin`, `/admin/users`, `/admin/users/{id}` · `PATCH .../status` · `DELETE /admin/users/{id}` | Admin panel (`admin` middleware) |

---

## 10. Security

- **Authentication:** bcrypt password hashing, strong password rules (breached-password check in production), login throttling, session regeneration, remember-me token rotation on password change, enumeration-safe password reset.
- **Authorization:** policies on every conversation, message and attachment; non-participants — including administrators — receive `404`. Admins cannot modify their own or other admin accounts. Suspended/inactive accounts are signed out everywhere.
- **CSRF** protection on all state-changing requests (forms and AJAX), **XSS** protection through Blade escaping, an escaping JS template tag and a nonce-based **Content-Security-Policy**; **SQL injection** prevented through Eloquent/bound parameters (LIKE wildcards escaped).
- **Uploads:** content-type detection plus extension allowlists, size and dimension limits, random server-side file names, private storage outside the web root, images re-encoded (metadata stripped), downloads served with `nosniff` and a sandboxing CSP.
- **Headers:** `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, HSTS over HTTPS.
- **Rate limiting** on login, registration, password reset, sending, search, typing, sync and other chat actions.
- **Privacy:** users who block you don't share presence; message text control/bidi-override characters are stripped; deleting for everyone wipes content and files.

---

## 11. Configuration reference

All chat settings live in [`config/chat.php`](config/chat.php) and can be tuned from `.env`:

| Variable | Default | Description |
|----------|---------|-------------|
| `CHAT_MESSAGES_PER_PAGE` | 30 | Messages loaded initially and per infinite-scroll page |
| `CHAT_MAX_IMAGE_KB` | 5120 | Max image size |
| `CHAT_MAX_DOCUMENT_KB` | 10240 | Max PDF/DOC/DOCX size |
| `CHAT_MAX_VOICE_KB` | 10240 | Max voice note size (5-minute limit) |
| `CHAT_MAX_AVATAR_KB` | 2048 | Max profile image size |
| `CHAT_ONLINE_THRESHOLD_SECONDS` | 120 | Activity window for "online" |
| `CHAT_EDIT_WINDOW_MINUTES` | 0 | Edit time limit (0 = unlimited) |
| `CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES` | 0 | "Delete for everyone" time limit (0 = unlimited) |
| `CHAT_POLLING_INTERVAL_MS` | 4000 | Polling interval when WebSockets are unavailable |
| `CHAT_CSP` | true | Content-Security-Policy header |

Artisan commands:

| Command | Description |
|---------|-------------|
| `php artisan chat:make-admin {email}` | Grant admin access to an existing user |
| `php artisan chat:sweep-presence` | Mark users without recent activity offline (scheduled every minute) |
| `npm run icons` | Regenerate `resources/icons/icons.json` from Lucide |

---

## 12. Troubleshooting

| Problem | Solution |
|---------|----------|
| Messages only arrive every few seconds | Reverb is not reachable (polling fallback). Start `php artisan reverb:start` and check `REVERB_HOST`/`REVERB_PORT`; restart after `.env` changes. |
| `419 Page Expired` | Session expired or `APP_URL`/domain mismatch — reload the page; ensure you use the same host as `APP_URL`. |
| Profile pictures don't load | Run `php artisan storage:link`. |
| Uploads fail with a validation error | Increase PHP `upload_max_filesize` / `post_max_size`; check the `CHAT_MAX_*` limits. |
| Voice recording unavailable | Browsers only allow the microphone on HTTPS or `localhost`; grant microphone permission. |
| Desktop notifications not shown | Allow notifications for the site in the browser (Settings → Appearance & alerts → Enable). |
| Vite manifest not found | Run `npm run build` (or keep `npm run dev` running). |
| Config changes ignored | `php artisan optimize:clear`. |
