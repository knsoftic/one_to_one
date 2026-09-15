# Development Progress — One2One Chat

Laravel 12 · PHP 8.2 · MySQL/MariaDB · Blade · Tailwind CSS 4 · Vanilla JS (AJAX) · Laravel Reverb (WebSockets)

| Phase | Scope | Status |
|-------|-------|--------|
| Phase 1 | Laravel installation, database, authentication, user profiles | ✅ Completed |
| Phase 2 | User search, conversation creation, basic one-to-one messages | ✅ Completed |
| Phase 3 | Real-time messaging, online status, typing indicator, seen status | ✅ Completed |
| Phase 4 | Image/file sending, voice messages, reply, edit, delete | ✅ Completed |
| Phase 5 | Notifications, block system, recent chats, unread counter | ✅ Completed |
| Phase 6 | Admin panel, dark mode, responsive UI, security improvements, testing | ✅ Completed |

---

## Phase 1 — Completed

### Installation & configuration
- Laravel 12.69 skeleton, Laravel Reverb 1.x installed, broadcasting scaffolded (`routes/channels.php`, `config/broadcasting.php`, `config/reverb.php`).
- MySQL database `one_to_one_chat` (app) and `one_to_one_chat_test` (PHPUnit) — utf8mb4.
- `.env` configured for MySQL, database sessions/cache/queue, Reverb credentials, chat limits and the initial admin.
- `config/chat.php` — central app settings (page size, upload limits/types, presence thresholds, reserved usernames).
- Private `chat` filesystem disk (`storage/app/private/chat`, never web-served); local disk `serve` disabled; `storage:link` for avatars.

### Database (migrations)
- `users` — name, **unique** username / email / phone, profile_image, password, is_online, last_seen, role, status, theme, notification preferences + indexes.
- `conversations` — user_one_id, user_two_id, last_message_id; **unique (user_one_id, user_two_id)** with participants always stored in ascending order (enforced in the model) → duplicate conversations impossible.
- `messages` — conversation/sender/receiver FKs, message, message_type, attachment (+ name, mime, size, meta JSON), reply_to_id (self FK), is_edited/edited_at, deleted_for_sender/receiver/everyone, sent_at/delivered_at/seen_at, composite indexes for history, unread counts and sync.
- `blocked_users` — user_id, blocked_user_id (unique pair), created_at.
- `notifications` (Laravel database notifications), `sessions`, `cache`, `jobs`, `password_reset_tokens`.

### Models & relationships
- `User` (sent/received messages, conversations as user one/two, blocks, blockedUsers, blockers; scopes `active`, `online`, `search`; helpers `isAdmin`, `isActive`, `isOnlineNow`, `hasBlocked`, `isBlockedBy`, `hasBlockWith`; avatar accessors).
- `Conversation` (userOne, userTwo, messages, lastMessage; scopes `forUser`, `between`; participant helpers).
- `Message` (conversation, sender, receiver, replyTo, replies; scopes `visibleTo`, `unreadFor`; status + preview helpers).
- `BlockedUser` (user, blockedUser).

### Authentication (custom, no starter kit)
- `Auth\AuthController` — login, register, logout. `Auth\PasswordResetController` — forgot / reset password (Laravel password broker, enumeration-safe response).
- Login with **email, username or mobile number**, "Remember me", rate limiting (5 attempts / minute per login+IP), session regeneration.
- Registration: full name, username, email, mobile, password + confirmation, optional profile image. Inputs normalised (lowercase email/username, phone digits).
- Profile images re-encoded with GD → 256×256 WebP (strips EXIF/metadata, neutralises payloads), memory-guarded.
- Middleware: `active` (signs out inactive/suspended accounts), `admin`, `TrackUserActivity` (throttled last_seen updates), `SecurityHeaders`.
- Form Requests: `RegisterRequest`, `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`, `UpdateProfileRequest`, `UpdatePasswordRequest`, `UpdatePreferencesRequest`.
- Password policy: min 8, mixed case, numbers (+ breached-password check in production).

### Profile settings
- `ProfileController` + `AccountService`: update name/username/email/mobile, change/remove profile picture, change password (current password required), theme + notification preferences (AJAX, JSON).
- Settings page with tabs: Profile · Password & security · Appearance & alerts · Blocked users · Log out.

### Frontend foundation
- Design system with CSS tokens; **light (default), dark and system** themes with no flash on load; theme persisted per user.
- Components: buttons, inputs with icons, password visibility toggle, strength meter, avatar (image or coloured initials), badges, cards, alerts, toasts, dropdowns, confirm modal, skeleton, switches, segmented control.
- Shared Lucide icon map (`npm run icons` → `resources/icons/icons.json`) used by both Blade `<x-icon>` and JS `icon()`.
- Responsive split-screen auth pages.

### Seeders & commands
- `AdminSeeder` (from `ADMIN_*` env), `DemoSeeder` (6 demo users, password `Password1`, non-production only).
- `php artisan chat:make-admin {email}`, `php artisan chat:sweep-presence` (scheduled every minute).

### Verification
- ✅ `php artisan migrate:fresh --seed` — all migrations run on MySQL, FKs & indexes verified.
- ✅ `php artisan route:list` — all routes registered.
- ✅ `npm run build` — Vite production build succeeds.
- ✅ Browser: login, register (validation errors + success), settings, theme switching (persisted to DB).
- ✅ PHPUnit — **35 tests passed** (registration, login by email/username/phone, remember me, rate limiting, suspended accounts, logout, password reset, profile/avatar/password/preferences, mass-assignment protection, security headers).

---

## Phase 2 — Completed

### Backend
- `ConversationService` — `findOrCreate()` (race-safe `createOrFirst` on the unique participant pair), `recentFor()` (sidebar list: latest message *visible to the viewer*, unread count, sorted by latest message time), `loadForUser()`.
- `MessageService` — id-cursor history pagination (`before`), `sendText()` (transaction + `last_message_id` update), text sanitising (strips control & bidi-override characters, keeps emoji joiners).
- `ConversationPolicy` — `view` (participants only, **404** for everyone else — admins included), `sendMessage` (participants, not blocked).
- Controllers: `ChatController` (dashboard shell, deep link `/chat/{id}`), `ConversationController` (index/store/show), `MessageController` (index/store), `UserController` (search, online).
- Form Requests: `StartConversationRequest`, `SendMessageRequest` (authorisation before validation, reply must belong to the same conversation).
- API Resources: `ConversationResource`, `MessageResource` (deleted content masked, attachment/reply payloads), `UserResource` (no email/phone for other users).
- User search by **name, username, email, mobile** (national "0345…" matches "+92345…"), LIKE wildcards escaped, suspended users excluded, rate limited.
- Routes: `GET /conversations`, `POST /conversations`, `GET /conversations/{id}`, `GET|POST /conversations/{id}/messages`, `GET /users/search`, `GET /users/online`, `GET /chat/{conversation}`.
- Factories for `Conversation` and `Message`; `DemoSeeder` now creates sample conversations.

### Frontend (chat dashboard)
- Sidebar: my profile + connection status, theme toggle, menu (new chat, settings, notifications, admin, logout), search (local chats + people), All/Unread filters, "Online now" strip, recent chats with avatar, name, last message, time, ticks, unread badge, skeleton loading.
- Chat panel: header (avatar, name, online / "last seen today at 10:35 PM"), date dividers, grouped bubbles (sender right, receiver left), time + ✓ / ✓✓ / blue ✓✓ status, links auto-detected safely, jumbo emoji, "Say hi" intro for new chats.
- Optimistic sending with pending/failed/retry states, send animation, Enter to send / Shift+Enter newline, autosizing composer, emoji picker (categories + recently used).
- Infinite scroll upward (30 messages per page, scroll position preserved), auto-scroll to latest, scroll-to-bottom button with unseen counter.
- Deep links + browser back/forward (`/chat/{id}`), mobile single-pane navigation with slide animations.
- All user content rendered through an escaping `html` template tag (XSS-safe).

### Verification
- ✅ `migrate:fresh --seed`, `route:list` (23 routes), `npm run build`.
- ✅ Browser: recent chats ordering & unread badges, open conversation, send message (HTML shown literally, URL linkified), emoji picker, search by phone in national format, start new conversation, mobile layout.
- ✅ PHPUnit — **56 tests passed** (adds conversation uniqueness at app + DB level, non-participant/admin access denied, recent chats ordering & unread counts, private fields hidden, search by all fields & wildcard escaping, sending, XSS payload stored raw, validation, reply scoping, cursor pagination, delete-for-me visibility, rate limiting, guest access).

### Notes
- MariaDB stopped unexpectedly during this phase (no crash entry in its log); it was restarted with XAMPP's standard `mysqld --defaults-file=mysql\bin\my.ini --standalone` and all data was intact.
- A temporary `DevAutoLogin` middleware (local + loopback only, `DEV_AUTO_LOGIN` in `.env`) is used for browser UI testing without typing passwords. **It will be removed in Phase 6.**

---

## Phase 3 — Completed

### Broadcasting (Laravel Reverb)
- Channels (`routes/channels.php`, authorised behind `web` + `auth` + `active`): private `App.Models.User.{id}` (only the owner) and presence channel `online`.
- Events (`ShouldBroadcastNow`): `MessageSent` (both participants, sending tab excluded via `toOthers()`), `MessagesStatusUpdated` (delivered / seen → original sender), `UserTyping` (other participant), `UserPresenceChanged` (online/offline + accurate last seen).
- `ResilientBroadcastManager` — broadcasting can never break or slow a request: failures are logged, and a 10-second circuit breaker skips further attempts while the WebSocket server is down. Short Guzzle timeouts for Reverb.

### Delivery & read receipts
- `sent_at` on send, `delivered_at` when the receiver's device acknowledges (`POST /messages/delivered`, batched) or on heartbeat, `seen_at` when the conversation is opened while the tab is visible (`POST /conversations/{id}/seen`).
- Only the receiver can update receipts; status never goes backwards; early receipts for still-sending messages are cached client-side.
- ✓ sent · ✓✓ delivered · blue ✓✓ seen, with a pop animation, in bubbles and in the recent chats list.

### Presence
- Presence channel `here / joining / leaving` for instant online/offline; `UserPresenceChanged` for accurate "last seen".
- `PresenceService`: throttled activity writes, heartbeat every 45 s (`POST /presence/heartbeat`), offline beacon on tab close (`navigator.sendBeacon` → `POST /presence/offline`), logout marks offline, `chat:sweep-presence` (scheduled) marks stale users offline.
- Header shows "online" / "last seen today at 10:35 PM"; sidebar avatars and the "Online now" strip update live.

### Typing indicator
- `TypingService`: throttled client pings (every 3 s while typing, stop after 3.5 s idle or on send), broadcast + short-lived cache entry (for polling). Blocked users cannot signal.
- Receiver sees "typing…" in the header, in the recent chats preview and as an animated typing bubble; it disappears automatically after 6 s without a ping.

### Polling fallback & catch-up (AJAX)
- `GET /chat/sync?since=` (`SyncService`): changed messages for the user (hidden entries for "deleted for me"), typing state of the open chat, contacts' presence; overlap window to avoid missed rows.
- Client (`resources/js/chat/realtime.js`): Echo + Reverb; if the socket is not connected within 4 s or drops, polls every 4 s; after reconnecting it syncs from the moment of disconnection; a 60 s safety sync runs while connected; unchanged messages are not re-rendered.
- Connection indicator in the sidebar (Online / Connecting… / Offline).

### Verification
- ✅ `route:list` — new routes: seen, delivered, typing, heartbeat, offline, sync, broadcasting/auth.
- ✅ Browser with two users (two tabs): WebSocket connected, presence both ways, typing indicator (header + sidebar + bubble), message delivered instantly to the other tab with unread counter & title badge, delivered ✓✓ then blue ✓✓ when the receiver's tab became visible.
- ✅ Reverb stopped: both tabs switched to polling, messages still sent (≈300 ms thanks to the circuit breaker), received and marked seen; Reverb restarted: both tabs reconnected automatically.
- ✅ Security: subscribing to another user's private channel is rejected (HTTP 403).
- ✅ PHPUnit — **68 tests passed** (adds broadcast channels/payload, delivered only by receiver, seen marking & idempotency, outsiders blocked, typing broadcast/cache, heartbeat delivery, offline beacon, presence sweep, sync scoping & hidden messages, message saved while WebSocket server is down).

---

## Phase 4 — Completed

### Attachments (images, PDF, DOC, DOCX) — secure storage
- `AttachmentService`: files stored on the private `chat` disk (`storage/app/private/chat/Y/m/{uuid}.{ext}`), random server-generated names, extension derived from detected content, sanitised display name.
- Images are re-encoded with GD (strips EXIF/GPS metadata, longest edge capped at 2560 px) and get a 480 px WebP thumbnail; width/height stored for layout-stable rendering.
- Validation (`SendMessageRequest`): content-type detection (`mimes` for images, allowlisted `mimetypes` for Word/PDF) **and** client extension (`extensions`), per-type size limits from `config/chat.php` (image 5 MB, document 10 MB, voice 10 MB), max image dimensions, voice/attachment mutually exclusive.
- `AttachmentController` + `MessagePolicy@view`: files are streamed only to participants (404 for everyone else, including admins, and after deletion), `nosniff`, restrictive CSP (`sandbox` for non-PDF), inline vs download disposition, HTTP range support for audio seeking. Relative URLs so both participants' hosts work.

### Voice notes
- Record (MediaRecorder, opus/webm → ogg → mp4 fallbacks), live timer & animated bars, stop, preview player, discard, send; 5-minute limit; duration stored in `attachment_meta`.
- Bubble player: play/pause, waveform progress, seek by clicking, one clip plays at a time.

### Message actions
- Hover button / right-click / long-press menu: **Reply, Copy text, Download, Edit, Delete**.
- **Reply**: context bar in the composer, quote block in the bubble (click scrolls to and highlights the original, loading older history if needed), masked preview if the original is deleted.
- **Edit** (own text messages; optional time window `CHAT_EDIT_WINDOW_MINUTES`; ↑ in an empty composer edits your last message): optimistic update with rollback, "Edited" label, broadcast via `MessageUpdated`.
- **Delete for me** (`deleted_for_sender` / `deleted_for_receiver`, broadcast to own devices via `MessageHidden`; files removed once both participants deleted it) and **Delete for everyone** (sender only, optional window `CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES`; text and files wiped, shows "This message was deleted").
- **Copy** via Clipboard API with fallback.

### Frontend
- Composer: attach button, drag & drop overlay, paste image from clipboard, attachment preview with caption, client-side type/size checks, microphone button when the composer is empty.
- Optimistic file bubbles with local preview and upload progress bar, retry on failure.
- Image bubbles with reserved aspect ratio, time overlay for media-only messages, full-screen lightbox (zoom on click, download, Esc to close).
- Document cards (type colour, name, size, download).

### Verification
- ✅ `route:list` — `PATCH/DELETE /messages/{message}`, `GET /messages/{message}/attachment`.
- ✅ Browser (two users): image with caption (thumbnail, lightbox on the other user's host), reply quote, edit (+ "Edited" on the other side in real time), delete for everyone (masked on the other side), PDF card, voice note recorded from a synthetic audio stream, played back with progress (HTTP 206 range responses), message options menu.
- ✅ Files on disk: UUID names under `storage/app/private/chat`, thumbnail generated, nothing under `public/`.
- ✅ PHPUnit — **85 tests passed** (adds image/PDF/DOCX/voice uploads with real content detection, disguised PHP/EXE/HTML/text files rejected, GIF rejected, size limits, attachment + voice conflict, participant-only downloads incl. admin denied, WebP thumbnails, edit rules & window, delete for me / everyone incl. file cleanup, reply masking).

---

## Phase 5 — Completed

### Notifications (Laravel Notifications + Events/Listeners)
- `NewMessageNotification` — `database` channel always (notification centre), `broadcast` channel when the user has notifications enabled; broadcast on the `sync` connection so no queue worker is required. Payload: "Ahmed Khan sent you a message", preview, conversation/message ids, public sender profile.
- `SendNewMessageNotification` listener on `MessageSent` (auto-discovered); failures are logged and never undo a sent message.
- `NotificationController` — `GET /notifications` (latest 20 + unread count), `POST /notifications/read` (all or per conversation). Opening a conversation marks its notifications read.
- Frontend `Notifier`:
  - in-app toast with avatar (click opens the chat) when the tab is visible and the chat isn't already open,
  - desktop notification (`Notification` API) when the tab is in the background, click focuses and opens the chat,
  - dismissible "Enable desktop notifications" banner, optional Web Audio chime (respects the Notification sound preference),
  - bell with unread badge and notification centre dropdown (mark all as read),
  - works in WebSocket and polling modes, de-duplicated per message.

### Block system
- `BlockService` + `BlockController` — `POST /users/{user}/block`, `DELETE /users/{user}/block` (idempotent, cannot block yourself; JSON for the chat, redirect for the settings page).
- `BlockStatusChanged` broadcast to both users → composer disables/enables instantly on both sides, typing indicator cleared.
- Rules (`ConversationPolicy@sendMessage`, `MessagePolicy@update`, typing): neither side can send, upload, edit or signal typing while a block exists; **existing messages remain visible**; deleting your own messages still works.
- `ConversationResource` exposes `blocked_by_me` / `blocked_me`; a user who blocked you does not share online status or last seen (masked server-side and client-side, hidden from the "Online now" strip).
- UI: "Block / Unblock {name}" in the conversation menu (with confirmation), composer notice ("You blocked this user. Unblock" / "You can't send messages to this user."), ban badge in recent chats, blocked users list with Unblock in Settings.

### Recent chats & unread counters
- Recent chats sorted by latest visible message and re-sorted live on every send/receive (bump animation).
- Per-conversation unread badges, total unread on the "Unread" filter chip, `(n)` in the tab title, app badge (`navigator.setAppBadge`) where supported, notification bell count — all updated instantly and cleared when the chat is read.

### Verification
- ✅ `event:list` — `MessageSent` ⇂ `SendNewMessageNotification`; `route:list` — block & notification routes.
- ✅ Browser (two users): message while another chat is open → toast "Ahmed Khan sent you a message", bell badge 1, Unread chip 1, title `(1)`; notification centre click opens the chat and clears all counters; block → both sides updated in real time (notice, disabled composer, hidden presence, ban badge, messages still visible); unblock → both restored, presence visible again.
- ✅ PHPUnit — **95 tests passed** (adds notification channels & payload, disabled notifications stored only, notification centre + read on open, per-user isolation, block/unblock idempotency & event, self-block rejected, blocked sending/uploading/typing/editing denied with history kept, block flags & presence masking, settings unblock redirect).

---

## Phase 6 — Completed

### Admin panel (`/admin`, separate `admin` middleware)
- `AdminController` + `AdminService` + `UserPolicy@manage` + `UpdateUserStatusRequest`.
- Dashboard: total users, online users, total conversations, total messages, new users (7 days), blocked users (+ block relations), messages today, 7-day message volume chart (counts only), account health (active / inactive / suspended), newest users.
- Users: search (name, username, email, mobile), filters (status, role, online only), pagination, per-user details page with aggregate activity counts.
- Actions with confirmation dialogs: **Activate, Deactivate, Suspend** (signs the user out everywhere immediately: sessions deleted, remember token rotated, marked offline) and **Delete** (removes conversations, messages, attachments, avatar, blocks, notifications, sessions — self-references broken first so foreign-key cascades are safe).
- Admins cannot modify their own account or other admins. **No private message content is shown anywhere in the admin panel**, and admins get 404 on conversation, message and attachment endpoints like any non-participant.
- Responsive layout (sidebar on desktop, scrollable top navigation on mobile, responsive table columns), light/dark themes.

### Dark mode & responsive UI
- Verified light, dark and system themes across auth, chat, settings, admin and error pages.
- Verified mobile (375 px), tablet and desktop layouts for chat and admin.

### Security improvements
- Nonce-based **Content-Security-Policy** on HTML pages (`script-src 'self' 'nonce-…'`, `object-src 'none'`, `frame-ancestors 'self'`, WebSocket origin allowlisted); automatically skipped with the Vite dev server; `CHAT_CSP` toggle. Verified the whole app (WebSockets, images, fonts, emoji, voice) runs with zero CSP violations.
- Session-ended handling in the frontend: 401 / 419 / suspended responses reload to the sign-in page (suspended users see "Your account has been suspended").
- **Removed the temporary `DevAutoLogin` development middleware** and its `.env` entry (a test asserts it no longer exists).
- Self-contained branded error pages (403, 404, 419, 429, 500, 503) that render even when the database/session is unavailable.
- Production guidance: `APP_DEBUG=false`, encrypted & secure session cookies, restricted Reverb origins, HTTPS.
- Code formatted with Laravel Pint.

### Documentation
- `README.md` — requirements, step-by-step installation (composer install, .env, APP_KEY, MySQL, migrations & seeders, storage link, npm install/build, Reverb credentials), running (serve, reverb:start, schedule:work), demo accounts, WebSocket configuration, XAMPP virtual host, production deployment (caching, Supervisor, cron, Nginx WebSocket proxy), testing, architecture, routes, security, configuration reference, troubleshooting.
- `.env.example` — fully documented MySQL, session, Reverb, chat and admin settings.

### Final verification
- ✅ `php artisan migrate:fresh --seed` on MySQL — all migrations and seeders succeed.
- ✅ `php artisan route:list` — **41 application routes**; `config:cache`, `route:cache`, `event:cache`, `view:cache` all succeed.
- ✅ `npm run build` — production build succeeds.
- ✅ Browser: admin dashboard, users list (light & dark), mobile admin, suspend flow with confirmation (user signed out in real time with a clear message), CSP-protected chat with live WebSocket connection, guest redirect, 404 page.
- ✅ PHPUnit — **108 tests, 596 assertions, all passing** (adds admin access control, statistics without message content, search & filters, activate/deactivate/suspend, protection of own/other admin accounts, full user deletion cleanup, CSP header & nonce, CSRF in the web stack, no credential-less sign-in, custom error pages, password hashing).

---

## Summary

All six phases are complete. The application provides secure, real-time one-to-one messaging (Laravel 12 + MySQL + Reverb) with attachments, voice notes, message actions, receipts, presence, typing indicators, notifications, blocking, an admin panel, light/dark/system themes and a responsive UI, backed by 108 automated tests and full installation documentation.

### Known environment notes
- During development MariaDB (XAMPP) stopped once without a crash entry and was restarted with XAMPP's standard command; if it is not running, start MySQL from the XAMPP Control Panel.
- A few image/PDF/voice files uploaded during browser testing remain in `storage/app/private/chat` from the pre-reset database; they are private and unreferenced and can be deleted safely.

---

## Enhancements — Simple sign-up, phone contacts, mobile UI

### Simpler registration
- Registration form no longer asks for a profile picture; fields are in a single, mobile-friendly column (full name → mobile → email → username → password).
- Username is suggested automatically from the full name (editable).
- After registering, a separate **optional "Add a profile photo" step** (`/welcome/photo`) shows a large tap-to-choose picker with **Save** and **Skip for now** (`OnboardingController`, `UpdateAvatarRequest`, `AccountService::updateAvatar`).

### Phone contacts (like WhatsApp)
- `contacts` table (owner, registered contact user, name as saved, number) and indexed `users.phone_suffix` (last 9 significant digits, kept in sync by the `User` model; existing users backfilled).
- `ContactService` matches phone-book numbers in any format (`0300 1234567`, `+92 300 1234567`, `0092…`) against registered, active users; **numbers of people who aren't registered are never stored**; you are never matched with yourself.
- Endpoints: `GET /contacts`, `POST /contacts/sync` (rate limited 6/min, 50/day), `DELETE /contacts/{contact}`.
- Frontend "New chat" panel: **Sync phone contacts** (Contact Picker API — Android Chrome), **Import contacts file** (.vcf export, any device), filter contacts, search other users, tap to start a chat.
- Saved contact names are shown in recent chats, the chat header, the online strip, reply quotes and notifications (`saved_name` on conversations).

### Mobile UI
- Bottom navigation (Chats with unread badge · Contacts · You) and a floating "New chat" button on phones.
- Bigger touch targets and text, compact headers, 16 px form fields (no iOS zoom), `interactive-widget=resizes-content` so the composer stays above the keyboard, browser toolbar colour follows the theme.
- Message actions open as a **bottom sheet** with a preview on phones/touch screens; **swipe a message right to reply**.
- Sign-up pages keep the brand bar in the flow on phones (no overlap).

### Verification
- ✅ `php artisan migrate` (2 new migrations, phone suffix backfill), `route:list` (46 routes), `npm run build`.
- ✅ Browser (375 px mobile + desktop): simplified register form with username suggestion, photo step, bottom nav + FAB, contacts import from a .vcf (3 matches, unregistered number ignored), saved names in chat list/header, bottom-sheet menu, swipe-to-reply.
- ✅ PHPUnit — **127 tests passed** (adds photo step, phone matching formats, contact sync/privacy/validation/rate limit, saved names on conversations, phone suffix sync).

---

## Enhancements — Android app (Capacitor), notifications without Firebase

### Mobile app (`mobile/`)
- **Capacitor 8** Android project (`com.hunario.chat`, "One2One Chat") that opens `https://chat.hunario.com`; web changes reach the app with a normal server deploy.
- `MainActivity`: branded splash kept until the page is visible (max 8 s); documents/downloads through the system download manager (session cookie sent only to our own host); opens the right chat when a notification is tapped.
- `NativeAppPlugin`: app info, read-only phone-book reader (`READ_CONTACTS`), notification permission, enable/disable background notifications, clear a chat's notifications, battery-optimisation exemption request.
- Plugins: `@capacitor/app` (back button, minimise) and Capacitor's built-in `SystemBars` (edge-to-edge insets, status bar icons follow the theme). **No Firebase / Google Play services.**
- Adaptive + legacy launcher icons, monochrome icon, notification icon, light/dark splash, offline page with *Try again* (`server.errorPath`).
- Permissions: internet, contacts (read), microphone, notifications, vibrate, network state, foreground service (special use), boot completed, battery-optimisation request. Backups and cleartext traffic disabled.
- Release signing via git-ignored `android/keystore.properties`; build guide in `mobile/README.md`.

### Notifications without Firebase
- The user asked not to use Firebase, so the app delivers notifications through **its own background connection** (the FCM implementation that was briefly added was removed).
- `ChatNotificationService` (foreground service, `specialUse`): OkHttp WebSocket to Reverb using the Pusher protocol, subscribes to `private-App.Models.User.{id}` and shows `NewMessageNotification` broadcasts; ping/pong keep-alive with dead-connection detection; exponential reconnect backoff (up to 5 min); reconnects when the network returns; polls the feed while the socket is down and always catches up after reconnecting; de-duplicates by notification id; stays quiet while the app is on screen.
- `MessageNotifier`: "Messages" channel (high importance) with one notification per chat (latest 6 lines, count, private on lock screen), group summary for several chats, dismiss tracking; quiet "Background connection" channel for the required service notification. Notifications of chats read elsewhere are removed on the next check.
- `BootReceiver` restarts the service after reboot / app update; the web app asks once to allow background running (battery exemption).

### Server
- `device_tokens` (user, SHA-256 token hash, platform, app version, last used). `POST /devices` (session) issues a random 64-character token and returns the connection details (WebSocket URL, channel, endpoints, poll interval, preview setting); registering again from the same phone replaces the old token; `DELETE /devices`.
- `routes/api.php` (stateless, `AuthenticateDevice` bearer-token middleware, `device-api` rate limit 60/min): `POST /api/device/broadcasting/auth` (signs only the device owner's private channel), `GET /api/device/notifications?after=` (unread new-message notifications, skips seen messages, respects the notification setting, returns unread conversation ids and a cursor), `DELETE /api/device`.
- Phones are signed out of notifications on logout (session-bound token), password change/reset, and when the account is suspended/deactivated.
- `NewMessageNotification` now includes the receiver's saved contact name (`sender.display_name`).
- Config: `chat.mobile.poll_interval_seconds` (`CHAT_MOBILE_POLL_SECONDS`), `chat.mobile.show_preview` (`CHAT_MOBILE_NOTIFICATION_PREVIEW`).

### Web app integration
- `resources/js/native/` (separate chunk, only inside the app): status bar style, Android back button (overlays → reply/voice/contacts panel → close chat → minimise), notification permission + device registration + service start, battery prompt (once), clearing a chat's notifications when it is opened, turning notifications off on sign-out, "Remember me" preselected.
- Contacts panel: in the app **Sync phone contacts** reads the whole phone book (asked on the first "New chat"), refreshes silently every 12 h; the `.vcf` import is hidden in the app.
- `native.css`: top safe-area padding for app shells (page is drawn behind the status bar).

### Verification
- ✅ `php artisan migrate`, `route:list` (device routes), `npm run build` (native chunk split out).
- ✅ Debug APK built with Gradle 9.3.1 / AGP 8.13 / JDK 25 (Android Studio JBR), target SDK 36, min SDK 24; Android lint: no errors.
- ✅ PHPUnit (adds 13 device tests: token issuing/replacement/validation, WebSocket URL, unknown and suspended tokens, own-channel-only signatures, feed with saved names/cursor/seen/opt-out/isolation, device sign-out, logout, password change).
- ⏳ On-device test of contacts permission, background notifications (screen off, after reboot) and downloads — needs a phone.

---

## Enhancements — Notifications while the app is open, internet status, permissions on open

### Notifications (app closed and open)
- Phone notifications now also appear **while the app is open**; only messages of the chat currently on screen are skipped (`setActiveConversation`, updated on `chat:opened` / `chat:closed`). The web app's in-app toast and chime are skipped inside the app when phone notifications are on, so nothing is shown twice.
- `KeepAliveReceiver`: inexact alarm about every 15 minutes (rescheduled on app start, boot and when notifications are enabled) restarts the background service if the phone killed it, or — when Android forbids a background start — fetches the notification feed once. Feed/presentation logic moved to `NotificationFeed`, shared with the service.

### No / slow internet
- Connection bar on every page (`resources/js/ui/network.js`, styles in `native.css`): *No internet connection* (offline event), *Can't reach the server* (requests fail while online), *Slow internet connection* (two API calls slower than 3.5 s, or a slow health check) and *Back online* for 2.5 s. Page shells make room for the bar instead of covering headers. Recovery is detected with `HEAD /up` pings (every 8 s while offline, 20 s while slow). The Network Information API speed estimate is not used — it reported "2g" on a fast local connection.
- App offline screen (`mobile/www/offline.html`): distinguishes no internet / server not answering / slow connection, retries automatically every 5 s and as soon as the network returns, *Try again* button.
- Native "Slow internet connection" / "No internet connection" screen when a page is still loading after 8 s, with *Try again*; reloads by itself when the network comes back.

### Permissions on first open
- `native/permissions.js`: when the chat opens, one explanation sheet lists only the permissions still missing (Notifications, Contacts, Microphone), then the system dialogs appear one after another. Granted or permanently blocked permissions are skipped; asked at most once per app launch. Afterwards contacts are matched silently and the battery exemption is requested once.
- `NativeAppPlugin`: `getPermissions()`, `requestPermission({ name })`, `setActiveConversation()`; microphone permission alias.

### Verification
- ✅ `npm run build`; browser check of the connection bar (offline → back online → hidden, 375 px and desktop).
- ✅ Debug APK build and Android lint (0 errors).
- ✅ PHPUnit — 140 tests passed.
- ⏳ On a phone: permission sheet, notifications with the app open/closed/after reboot, slow-internet and offline screens.

---

## Enhancements — WhatsApp-style push notifications (Firebase)

The user chose Firebase Cloud Messaging (free) for push, with notifications that look and behave like WhatsApp. The app's own background connection is kept as an automatic fallback for phones without Google Play services.

### Server
- `device_tokens.fcm_token` / `fcm_token_hash` (unique: a Firebase token belongs to one account; moved when the phone signs into another account).
- `PushService`: FCM HTTP v1 without an SDK (RS256 service-account JWT → cached OAuth token), **data-only** messages so the app draws every notification itself; clears tokens of uninstalled apps (`UNREGISTERED`). Enabled by `FCM_CREDENTIALS`.
- `SendMessagePush` (after the response, high priority): notification id shared with the notification centre/realtime event, conversation, message, sender id, saved contact name, avatar URL, initials, colour, preview, sent time.
- `SendReadPush` (normal priority) from `MessageService::markSeen`: removes the chat's notification from the reader's phones when it is read anywhere.
- Device API: `PUT /api/device/push-token`, `POST /api/device/messages/delivered` (✓✓ from a closed app, own messages only), `POST /api/device/conversations/{id}/messages` (Reply — same block/participant rules as the web, marks the chat read), `POST /api/device/conversations/{id}/read` (Mark as read). Connection details include `push.fcm` and the new endpoints.

### Android
- Firebase BoM 34.19 + `firebase-messaging`; `google-services` plugin applied only when `app/google-services.json` exists (the APK still builds without it).
- `PushRegistrar` picks Firebase push (token uploaded → fallback service and safety check stopped) or the fallback connection; a temporary failure never undoes push that already worked.
- `PushMessagingService` shows messages and marks them delivered, handles `read` pushes; `onNewToken` re-uploads.
- `MessageNotifier` rewritten: `MessagingStyle` per chat with sender `Person` + photo (`AvatarLoader`: own-server photo cached 7 days, or initials in the user's colour), earlier messages rebuilt from the notification on screen, Reply (`RemoteInput`) and Mark as read actions, "not sent" state, group summary, long-lived conversation shortcuts (Android 11+ Conversations section).
- Web app asks for the battery exemption only when the phone uses the fallback connection.

### Verification
- ✅ PHPUnit — **151 tests passed** (11 new: token save/move/clear, `push.fcm` flag, data payload with saved name/avatar/notification id, no push without credentials or with notifications off, read push, stale token, delivered, Reply incl. block/participant rules, Mark as read).
- ✅ APK builds without and with a (placeholder) `google-services.json` — `processDebugGoogleServices` runs and `google_app_id` is generated; Android lint 0 errors.
- ⏳ Needs the real Firebase project: `google-services.json` for the APK and the service-account JSON on the server; then on-device checks.

---

## Enhancements — Voice & video calls

WhatsApp-style one-to-one calls in the browser and the Android app. Media is peer-to-peer (WebRTC); the server coordinates the call and relays signaling.

### Server
- `calls` (ringing → ongoing → ended with `completed / declined / missed / cancelled / busy / failed`, answering device, duration, last heartbeat per side) and `call_signals` (offer / answer / ICE candidate / media state per device, deleted when the call ends).
- `CallService`: start (row-locked against double calls; callee already in a call → ends as *busy*), ringing ack, accept on exactly one device (409 for the others), decline, hang up, signaling, heartbeat, `expireStale()` (unanswered after ring timeout → missed; both devices silent → closed with the duration until they were last heard from). Every ended call adds a **call-history message** (`message_type = call`), unread for the callee only when missed; missed calls notify like messages ("Missed voice call from …", push included).
- Events `call.incoming` (callee), `call.updated` (both), `call.signal` (recipient; session descriptions above 6 KB are fetched over HTTP because of Reverb's request size limit).
- `IceServerService`: STUN + optional TURN with coturn short-lived HMAC credentials (`CHAT_CALL_TURN_*`).
- Firebase pushes `call` (high priority, TTL = ring timeout) and `call_state` (stop ringing when answered elsewhere / ended); device API to mark ringing, decline and hang up from the phone while the app is closed.
- Routes under `/conversations/{id}/calls` and `/calls/{call}/…` with policies (participants only, 404 otherwise; blocked users 403) and rate limits; polling sync includes active calls; `chat:expire-calls` scheduled every minute; call history cannot be deleted for everyone.

### Web
- `resources/js/chat/calls.js`: call buttons in the chat header and "call again" on history bubbles; full-screen call view (incoming / calling / ringing / connecting / timer / reconnecting / ended), mute, camera on/off, switch camera, minimise to a floating pill, remote mute/camera-off indicators, generated ringtone/ringback, background-tab browser notification.
- One client id per tab/phone; candidates queued until the matching description is applied; ICE restart by the caller (up to 3×) and "Couldn't connect" after 35 s; polling for state and signals so calls also work without WebSockets; heartbeats; ending the call when the page is closed.
- Call history bubbles and chat-list previews from each side's perspective ("Missed voice call", "Video call · 3:24", "No answer").
- `resources/css/calls.css`; Permissions-Policy allows the camera.

### Android
- Full-screen incoming call over the lock screen (`IncomingCallActivity`) and ringing `CallStyle` notification with Answer / Decline (`CallNotifier`, plain actions when full-screen intents are not allowed), from Firebase or the fallback WebSocket; rings inside the open app instead when the chat page can take it (`CallDispatcher`).
- Answer opens the chat with `?call=ID&answer=1` and connects immediately; `OngoingCallService` (microphone/camera foreground service) keeps the call alive in the background with an "Ongoing call" notification and Hang up, earpiece/speaker routing and the proximity sensor; `CallRinger` plays the phone's ringtone while the app is open.
- Camera permission added to the permissions asked on first open.

### Verification
- ✅ PHPUnit — **171 tests passed** (20 new in `CallTest`: start/permissions/busy, ringing, single-device answer, decline, cancel → missed, duration, strangers, history deletion, signal routing and cleanup, sync, stale calls, TURN credentials, pushes, phone decline).
- ✅ Vite build; call screen rendered in a browser harness (incoming and connected states).
- ✅ Android debug APK builds; Android lint 0 errors.
- ⏳ Needs real devices: two accounts calling each other (Wi-Fi and mobile data), and a TURN server on the production server for mobile networks (aaPanel guide step 19).

---

## Fixes — Unreliable calls and phone notifications, TURN setup

Reported: calls sometimes don't go through (never from the PC), phone notifications arrive only sometimes; calls on mobile data needed.

### What the live site showed (chat.hunario.com, checked from outside)
- `/app/` and `/apps/` answer with Nginx 404 — the Reverb WebSocket proxy is missing — and the CSP still lists `ws://127.0.0.1:8080` (local `REVERB_*` values). Every browser and phone therefore runs on polling. Background browser tabs are throttled to about one poll per minute, so incoming calls (45 s ring) were often missed; the phones' fallback connection checked only every 60 s and never receives calls.
- The new call code is deployed (`/calls/active` → 401).

### Causes found in the code
- Phones registered only on first sign-in / account change. A phone that registered before Firebase was configured kept `server_push = false` and stayed on the fallback connection forever (no push, no ringing).
- Desktop PCs without a microphone could not start or answer calls (`NotFoundError`).
- Answering could end the call if updating the peer connection's ICE servers threw.

### Changes
- `DeviceService::configVersion()` (Firebase on/off, WebSocket address, endpoints…) is shared with every page and returned by `POST /devices`; the Android app registers again whenever it differs, so phones switch to Firebase push / the fixed WebSocket address on the next app start — no new APK needed.
- Calls: join without a microphone or camera (receive-only, "No microphone on this device"), safe ICE server update when answering.
- `php artisan chat:doctor`: checks APP_URL/secure cookie, migrations, Reverb settings, the Reverb process, Laravel → Reverb publishing, a real WebSocket handshake through Nginx, Firebase credentials, phones not yet on push, TURN (STUN binding to coturn), abandoned calls and the scheduler (new heartbeat task), each with a fix.
- `scripts/setup-realtime.sh`: public Reverb values in `.env`, Nginx proxy blocks inserted into the aaPanel site config (backup, `nginx -t`, automatic rollback), Reverb under systemd when nothing runs yet, secure session cookie, config cache, doctor.
- `scripts/setup-turn.sh`: installs and configures coturn (shared secret, relay ports 49160–49400, private networks denied, TLS with the site certificate + monthly refresh), opens ufw/firewalld ports, sets `CHAT_CALL_TURN_URLS` / `CHAT_CALL_TURN_SECRET`, doctor.
- Guide: shortcuts in steps 10 and 19, doctor in step 14 and at the top of troubleshooting.

### Verification
- ✅ PHPUnit — **175 tests passed** (4 new in `DiagnosticsTest`).
- ✅ Doctor locally; its WebSocket handshake check returns 404 against chat.hunario.com (confirms the missing proxy) and its STUN check succeeds against Google's STUN server.
- ✅ Both scripts pass `bash -n`; not run on the production server from here.

---

## Roadmap Phase 1 — Message features (complete)

Built one feature at a time from [`docs/FEATURE-ROADMAP.md`](docs/FEATURE-ROADMAP.md); each has server tests (PHPUnit) and, where the logic lives in the browser, unit tests (`npm test`, Vitest — added in this phase with happy-dom for DOM helpers).

### M1 — Forward message ✅
- `POST /messages/{message}/forward` with `conversation_ids` (1–5, WhatsApp's limit). Every chat must be the user's and allow sending (block rules); call history and deleted messages cannot be forwarded.
- Files are **copied** (`AttachmentService::duplicate`, thumbnail included), so deleting one message never removes another's attachment.
- `messages.forward_count` (migration): copies get `original + 1`; the resource exposes `forwarded` and `forwarded_many` (≥ 5 → "Forwarded many times").
- UI: "Forward" in the message menu → dialog with recent chats and saved contacts (a chat is created for contacts first), search, up to 5 selections; forwarded messages show a "Forwarded" label.
- Tests: `ForwardMessageTest` (4).

### M2 — Emoji reactions ✅
- `message_reactions` table (one per person per message). `PUT /messages/{message}/reaction {emoji}` sets or replaces, `DELETE` removes; `SingleEmoji` rule accepts one emoji incl. skin tones, flags and ZWJ sequences.
- Reactions are included in history, polling sync and `message.updated` broadcasts (`[{emoji, count, user_ids}]`, only when loaded so partial updates never wipe them); the message is touched so polling clients see changes; deleting for everyone removes them. Not allowed on call history or while blocked.
- UI: quick bar (👍 ❤️ 😂 😮 😢 🙏 + full emoji picker) at the top of the message menu / long-press sheet and on a smiley button beside bubbles (mouse); pill under the bubble; tapping it shows who reacted and lets you remove yours; optimistic updates with rollback.
- Tests: `MessageReactionTest` (5), `reactions.test.js` (3).

### M3 — Search inside a chat ✅
- `GET /conversations/{conversation}/messages/search?q=` (2–100 characters): text and file names the user can still see, newest first, max 50; `%` and `_` are matched literally.
- UI: search icon in the chat header (desktop) and "Search" in the chat menu; bar with result count, older/newer arrows, results list with dates; jumping loads older history in pages of 100 when needed and highlights the words (text nodes only, links untouched).
- Tests: `MessageSearchTest` (4), `search.test.js` (3).

### M4 — Text formatting ✅
- WhatsApp syntax rendered in bubbles: `*bold*`, `_italic_`, `~strike~`, `` `inline code` ``, ```` ```monospace block``` ````, `- ` / `* ` bullet lists, `1. ` numbered lists and `> ` quotes.
- Text is HTML-escaped **before** formatting; links and code are protected with placeholders so markers inside them are left alone; markers must sit on word boundaries (`2*3*4` stays plain).
- Chat list previews, push notifications and reply quotes show the text without markers (`Message::stripFormatting` on the server, `stripFormatting` in the browser).
- Tests: `MessagePreviewTest` (2), `formatting.test.js` (6).

### M5 — Star messages ✅
- `starred_messages` table (per person). `POST/DELETE /messages/{message}/star`; `GET /starred` lists starred messages newest first (30 per page) with the chat partner's saved name. History and sync include `is_starred` for the viewer only.
- Deleting a message for me removes my star; deleting for everyone removes all stars.
- UI: "Star" / "Unstar" in the message menu, a small star in the bubble's time line, "Starred messages" in the sidebar menu → panel with each message, its chat and date; tapping one opens the chat and scrolls to the message (loading older history when needed).
- Tests: `StarredMessageTest` (4).

### M6 — Pin messages ✅
- `pinned_messages` table: 24 hours, 7 days or 30 days; up to 3 per chat (pinning a 4th replaces the oldest); expired pins are ignored and cleaned up. `POST/DELETE /messages/{message}/pin`. Not allowed on call history or while blocked; deleting a message for everyone unpins it.
- Both people see pins: the chat payload carries `pinned_messages`, and a `conversation.pins` broadcast refreshes the other side.
- UI: "Pin" / "Unpin" in the message menu with a duration choice; bar under the chat header ("Pinned message 1 of 2", preview, dots) — tapping it jumps to the pin and cycles through them.
- Tests: `PinnedMessageTest` (4).

### M7 — Message info ✅
- "Info" in the menu of your own messages: the message with Read, Delivered and Sent times (from `read_at` / `delivered_at` / `created_at`, kept live by the existing receipts).

### M8 — "Recording audio…" ✅
- The typing endpoint accepts `action` = `typing` | `recording`; it travels in the `user.typing` broadcast and in polling sync. The voice recorder reports it while recording and clears it on stop/cancel.
- UI: "recording audio…" in the chat header and chat list, typing bubble with a microphone.
- Tests: `RealtimeTest::test_recording_audio_is_reported_like_typing`.

### M9 — Voice note speed ✅
- 1× / 1.5× / 2× chip on every voice note (remembered on the device); when a voice note ends the next consecutive voice note from the same person plays automatically.
- Tests: `voice.test.js` (3).

### M10 — Drafts ✅
- Unsent text is saved per chat on the device (per account, 30 days, newest 100) while typing and when switching chats; it comes back when the chat is reopened and is cleared when the message (or a file) is sent.
- UI: chat list shows **Draft:** with the text instead of the last message.
- Tests: `drafts.test.js` (2).

### M11 — Link previews ✅
- The **server** fetches the first link of a text message (the other person's phone never contacts the site): Open Graph / Twitter card / `<title>` + meta description, site name and image. Results are cached per link in `link_previews` (fresh 7 days; pages without details retried after 1 hour) and messages point to them (`messages.link_preview_id`). Links inside `code` are ignored.
- **Safety (SSRF):** `App\Support\SafeFetcher` only allows http/https on ports 80/443 without credentials; refuses `localhost`, `.local`/`.internal` names and numeric shorthand hosts; every resolved address must be public (private, loopback, link-local, CGNAT, multicast, reserved and IPv4-in-IPv6 ranges are refused) and cURL is pinned to the checked address (no DNS rebinding, no proxy); redirects are followed one hop at a time (max 4) and re-checked; 5 s timeout; pages read up to 512 KB, images up to 5 MB (after decompression). Images are re-encoded to a 480 px WebP on the private disk and served by `/link-previews/{id}/image` with `nosniff` and a sandbox CSP.
- Sending: a cached preview is attached at once; otherwise `AttachLinkPreview` runs after the response and broadcasts `message.updated` to both people (including the sender's tab). `link_preview=false` sends without one. Editing to another link replaces it; deleting for everyone removes it; forwarding keeps it.
- Composer: `POST /link-preview` (30/min, POST so typed links stay out of access logs) shows "Fetching preview…" and then the card above the input with an ✕ to remove it; the optimistic bubble already shows the card.
- Bubble: card with a large image (wide pictures) or a small thumbnail, title (2 lines), description (2 lines) and site; opens the link in a new tab (`noopener noreferrer nofollow`). Only images from this site's own path are rendered.
- Unused previews and their images are removed by the daily `model:prune` schedule. Setting: `CHAT_LINK_PREVIEWS` (default on).
- Tests: `SafeFetcherTest` (29 incl. 25 address cases), `LinkPreviewTest` (8), `link-preview.test.js` (5). Checked once against a real page (GitHub) locally: title, description, site and image were stored; `http://127.0.0.1/` was refused. Card layout checked in the test page (fixed: a long title stretched the bubble).

### M12 — Videos ✅
- New message type `video`: MP4/M4V, WebM, MOV and 3GP, checked by detected content type **and** extension; 16 MB by default (`CHAT_MAX_VIDEO_KB`, kept below the PHP/Nginx upload limits in the aaPanel guide, which now explains how to raise them).
- The sender's browser reads the video (`video.js` → `videoDetails`): a poster frame a little way in, width/height and duration. The poster is uploaded with the video (`thumbnail`, max 1 MB, image rules) and re-encoded to WebP on the server, which also gives the bubble its shape. Videos the browser cannot decode still send (dark placeholder with a film icon).
- Playback: stored privately like other files, served inline with Range support (seeking/streaming, `206 Partial Content`) to the two participants only. Tapping opens a full-screen player (autoplay, controls, download).
- Bubble: poster with play button, duration badge, upload progress; caption support; "🎥 Video" / "🎥 caption" in the chat list, notifications and replies. Forwarding copies the video and poster.
- Tests: `VideoMessageTest` (5: storage + poster + duration, inline Range playback for participants only, no poster, disguised/oversized/invalid poster/duration rejected, forwarding), `video.test.js` (3). Checked in Chrome with a recorded WebM: poster captured in ~50 ms at the right size and duration, bubble/placeholder/portrait layouts and the player work.

### M13 — Several photos at once, with captions, as an album ✅
- Pick (or paste / drop) up to **30 files** at once. The composer shows a tray of thumbnails with "+" to add more and a remove button; the message box holds the caption of the selected item (tap another thumbnail to caption it; a dot marks items that already have one). Text typed before picking becomes the first caption.
- Photos and videos picked together get one `album_id` (UUID, also generated where `crypto.randomUUID` is missing); the server stores it in the attachment metadata and returns `album_id`. It is dropped when forwarding or deleting for everyone.
- Bubbles appear at once in the picked order and uploads run **one after another** (`prepareFile` + upload queue), so the stored order matches; retries keep their place.
- Display (`album.js`): 4+ consecutive uncaptioned photos/videos of one album from the same person form a two-column grid on the sender's side; beyond four, the fourth tile shows "+N" and tapping it opens the whole album. Two or three items, captioned items and deleted items stay normal bubbles. The time shows on the last visible tile. The message list became a CSS grid for this (other rows still span the full width).
- Tests: `AlbumMessageTest` (2), `album.test.js` (4). Checked in a test page: album grids on both sides with "+2" and a video tile, time on the "+N" tile, tray with photo/video/loading/document items and caption dots.

### M14 — Edit a photo before sending ✅
- A ✏️ button on a picked photo (single preview or the selected item in the tray) opens a full-screen editor (`image-editor.js`, loaded only when needed): **crop** (drag corners or move the frame, rule-of-thirds grid, Reset/Crop), **rotate** 90°, **draw** (7 colours, 3 brush sizes) and **text** (tap the photo, type, Enter; outlined so it reads on any background), with **undo** (button or Ctrl+Z), Cancel and Done.
- Edits are a list of operations replayed on the original (exact undo); the photo is worked on at up to 2560 px (the size the server keeps), honouring EXIF orientation. Done saves a JPEG (PNG stays PNG unless it would pass the size limit); an unchanged photo is sent as the original file. The server treats the result like any photo (re-encoded, metadata removed).
- Works with mouse, touch and pen (pointer events); Esc leaves crop mode or cancels.
- Tests: `image-editor.test.js` (4: crop corner/move limits, rotation size, output format). Checked in Chrome on a 1200×800 photo: stroke and text drawn, crop frame aligned to the photo and dragged, crop → 900×720, rotate → 720×900, exported JPEG decodes at 720×900. Fixed while checking: the canvas overflowed short screens, and the crop shade dimmed the toolbar.

### M15 — Camera inside the app ✅
- 📷 button next to the paperclip (shown only where the browser can use a camera) opens a full-screen camera (`camera.js`, loaded on demand): back camera first, switch button when there is more than one camera, **Photo** / **Video** modes, big shutter.
- Photo: the current frame at full camera resolution as JPEG (selfies are saved as seen on screen). Video: recorded with MediaRecorder at ~1.5 Mbit/s with a running timer and an automatic stop that keeps the file under the video upload limit (75 s at the default 16 MB); the microphone is asked for only when recording starts, and without one the video records silently with a warning.
- The result lands in the attachment tray like a picked file, so it can be edited (M14), captioned and sent together with other files (M13). Clear messages when the camera is blocked or missing; closing while recording discards the clip and always releases the camera.
- Format: WebM (VP9/VP8 + Opus) where supported, MP4 on Safari. While testing, Chromium reported MP4 as supported but wrote **empty** MP4 files at 720p, so WebM is preferred and an empty recording shows "could not be recorded".
- Android app: uses the WebView camera/microphone permissions already declared for calls; no new APK needed.
- Tests: `camera.test.js` (3: format choice, length limit, file names). The browser pane blocks real cameras, so the blocked-camera message was checked there, and capture was checked with a synthetic 1280×720 camera stream: photo → `IMG_….jpg` (tray hand-off, camera closed), 2.7 s video → `VID_….webm` (253 KB) with the no-microphone warning, recording screen with timer and limit.

### M16 — More file types and HD photos ✅
- Documents now include **Word, Excel, PowerPoint** (old and new formats), **OpenDocument**, RTF, **TXT, CSV, ZIP, RAR, 7Z, MP3 and M4A** (10 MB). Each extension has its own list of content types detected from the file (`chat.uploads.document.types` + `App\Rules\DocumentType`), so a web page renamed `.txt`, a ZIP renamed `.txt` or a program renamed `.zip` is refused. **Programs and installers (EXE, APK, JS…) are deliberately not allowed** (the roadmap mentioned APK; left out for safety). Text files are always downloaded (never shown as a page) with `nosniff` and a sandbox policy.
- Bubbles show the kind of file with its own icon and colour (spreadsheet green, slides orange, archive brown, audio purple, PDF red, text grey) and a friendly label ("Excel · 47 KB"). The file picker's accepted types come from the server config.
- **HD photos:** photos are resized in the browser before upload like WhatsApp — standard 1600 px (JPEG 82%) or **HD** 3072 px (90%) with an "HD" switch in the tray; this saves data, lets big phone photos (up to 40 MB originals) through the 5 MB upload limit and removes EXIF/GPS on the device. The server keeps the same sizes (`image.max_edge` / `hd_max_edge`, `quality=hd`), stores `hd` and the bubble shows an "HD" badge. Standard photos are now kept at 1600 px instead of 2560 px.
- Tests: `FileTypesTest` (10: Excel, PowerPoint, TXT, CSV, ZIP, RAR, MP3 accepted; six disguised/forbidden files refused; text download headers; standard vs HD sizes), `files.test.js` (3). Checked in Chrome: a 4000×3000 photo became 1600×1200 (26 KB) standard and 3072×2304 HD, a small photo was sent unchanged; file bubbles, HD badge and tray HD switch rendered correctly.

### M17 — GIFs and stickers ✅
- **GIF files** can be sent and stay animated: they are stored unchanged (other photos are still re-encoded), shown with a "GIF" badge and "👾 GIF" in previews; editing and HD are skipped for them.
- **GIF search** (optional, `CHAT_TENOR_KEY`): a "GIFs" tab in the emoji panel with trending GIFs, search and "More". The key stays on the server (`GET /gifs`); a chosen GIF is downloaded **by the server** from Tenor's media host only (through the SafeFetcher protections, max 8 MB, must really be a GIF) and stored like any attachment, so the other person never loads anything from Tenor. Tenor's image host is added to the page's image policy only when the key is set. "Powered by Tenor" credit is shown.
- **Stickers** (new message type `sticker`, 512×512 WebP): a "Stickers" tab with **My stickers** (most recently used first, remove button), **From a photo** (sticker maker: fill or whole photo, square / rounded / circle, white outline, transparent background) and **Emoji stickers** (24 big emoji rendered as stickers). Received stickers can be saved with "Save to my stickers". Stickers are shown without a bubble. The server re-encodes every sticker image, de-duplicates per person, keeps up to 200 and copies the file into each message (removing a sticker never breaks sent messages). Only the owner can see/delete their collection; another person's sticker id is refused.
- Tests: `StickerAndGifTest` (7: sticker from photo is a transparent 512 WebP and de-duplicated, owner-only access, sending copies the file and the receiver can save it, foreign stickers and non-sticker messages refused, GIF upload stored byte-for-byte, GIF search off without a key, search results + sending via the server with only Tenor media accepted, rogue/non-GIF downloads refused), `stickers.test.js` (2); the old test that expected GIF uploads to be rejected now uses a BMP. Checked in Chrome: sticker/GIF tabs, emoji sticker rendering (WebP upload), GIF list with "More" and sending, sticker maker (circle + outline, transparent corners, WebP result), sticker and GIF bubbles.

### M18 — Location and live location ✅
- The paperclip now opens an **attach menu** (Photos, videos & files · Location; later features add Contact and Poll).
- **Location** dialog finds your position (high accuracy, shows "Accurate to 9 m") and offers **Send your current location** or **Share live location** for 15 minutes, 1 hour or 8 hours. Clear messages when location is blocked, unavailable or too slow.
- New message type `location` (coordinates rounded to 6 decimals, accuracy in metres). Bubble: map-style card with a pin (no map images are loaded from other sites, for privacy and map-licence reasons), title and details, **Open in Maps** (Google Maps link) and, for your own active live location, **Stop sharing**. Previews "📍 Location" / "📍 Live location".
- **Live location:** this device watches its position and sends updates at most every 15 s (and only when it moved ≥ 15 m, otherwise once a minute) with `PATCH /messages/{id}/location` (sender only, 12/min); the other person sees the card move through the normal `message.updated` broadcast/sync. It ends at the chosen time, with "Stop sharing" (`DELETE`), or when sharing stops elsewhere; ended cards show "Live location ended · Last updated …". Sharing resumes when the chat is reopened. **Limitation:** updates are sent while the chat is open in that browser tab or in the app (no background tracking).
- Forwarding a location sends the last known point as a normal location. Page policy now allows location for this site (`geolocation=(self)`).
- Android: `ACCESS_COARSE_LOCATION` / `ACCESS_FINE_LOCATION` added to the manifest — **a new APK build is needed** for location in the Android app (the website works now).
- Tests: `LocationMessageTest` (5: rounding and both sides, invalid coordinates/durations/extra fields refused, live updates broadcast + sender-only + stop, automatic end + forwarding the last point, Permissions-Policy), `location.test.js` (4: distance, update throttling, end detection, card + stop button). Checked in Chrome with a simulated GPS: attach menu, dialog with accuracy, live 15-minute share (correct payload, one watcher), the first move within 15 s was skipped and an 80 m move after 20 s was sent, Stop sharing cleared the watcher and showed "Live location ended"; static and ended cards rendered.

### M19 — Contact cards ✅
- "Contact" in the attach menu opens a picker: the **phone book** in the Android app (already-granted contacts permission), **Choose from phone** where the browser has the Contact Picker (Android Chrome), otherwise your **saved contacts**; search by name or number; or **Type a contact** (name + number).
- New message type `contact`: name (cleaned, 100 chars) and up to 5 numbers. If a number belongs to someone in the **sender's own saved contacts**, the card links to that account and shows **Message** (opens the chat). Numbers are never looked up among all users, so contact cards cannot be used to find out who is registered.
- Card: initials, name, numbers, @username when linked, and **Message / Save contact / Call** (Save downloads a proper vCard 3.0 from `/messages/{id}/contact.vcf`, participants only, escaped per RFC 6350). Preview "👤 Contact: name". Forwarding keeps the card.
- Tests: `ContactCardTest` (4: linking only from saved contacts, invalid cards refused, vCard download + escaping + participants only, forwarding), `contact-share.test.js` (3). Checked in Chrome: cards on both sides with the right buttons, picker list and search ("hina" → Hina Office), choosing a contact sends `{name, phones}`.

### M20 — Polls ✅
- "Poll" in the attach menu: question (255 chars), options (a new row appears as you type, 2–12, duplicates ignored), **Allow multiple answers** (on by default, like WhatsApp), with clear errors.
- New message type `poll`; the question and numbered options are stored with the message and votes in `poll_votes` (one row per chosen option). `PUT /messages/{id}/vote {options: [...]}` replaces your answers (empty = remove); single-answer polls refuse more than one; unknown options, other people and blocked chats are refused. Votes are broadcast with `message.updated` and picked up by sync.
- Bubble: question, "Select one / Select one or more", each option with a round or square check, count and green bar, total votes; hovering an option shows who voted ("You", name). Tapping updates at once and rolls back if saving fails. Preview "📊 Poll: question". Forwarding sends the poll without votes; deleting for everyone removes the votes.
- Tests: `PollTest` (5: creation/cleanup, validation, single-answer replace/remove + broadcast, multiple answers + who can vote, history/forward/delete), `poll.test.js` (4). Checked in Chrome: moving a single answer, adding a multiple answer, bars on both bubble colours, creator adding rows and sending `{question, options, multiple}`, duplicate-options error.

### M21 — Disappearing messages ✅
- Chat menu → **Disappearing messages** (shows the current setting): Off, 24 hours, 7 days or 90 days. Either person can change it (not while blocked); `PUT /conversations/{id}/disappearing`.
- Changing it leaves a centred **notice** in the chat for both people ("⏱️ Disappearing messages turned on: new messages disappear after 7 days" / "…turned off"), new message type `system` (never disappears, no menu, cannot be forwarded, reacted to or pinned, and does not send a notification).
- New messages get `messages.expires_at` (earlier messages are not affected); bubbles show a small timer icon and the chat header a timer next to the name.
- `php artisan chat:expire-messages` (scheduled every minute) removes expired messages **for both people** together with their files; reactions, stars, pins and poll votes go with them; the chat's last message is recalculated and a `messages.expired` event updates open screens. Open chats also hide expired messages on their own (every 15 s), so polling clients stay correct. Needs the scheduler cron (already part of the aaPanel guide).
- Tests: `DisappearingMessageTest` (5: notice + end time + same value twice + turning off, notices do not notify, allowed durations/participants/blocked, removal with files and cascades + last message + event to both, notices cannot be forwarded/reacted/pinned), `disappearing.test.js` (2). Checked in Chrome: notice chip, timer icons in bubbles and header, dialog with the current value selected and saving 7 days.

### M22 — View once ✅
- A dashed **"1"** switch sends photos and videos (tray) or a voice message (voice preview) as **view once**; GIFs and files cannot be view once, and view once media is never grouped into an album.
- The server stores `view_once` and never gives links for it: the normal attachment route refuses it, previews say "📷 View once photo / 🎥 View once video / 🎤 View once voice message" (no caption), and it cannot be forwarded, starred, pinned or downloaded from the menu.
- The **receiver** taps "Photo / Video / Voice message" once: `POST /messages/{id}/view-once` records `opened_at`, tells the sender ("Opened") and returns a **signed link valid for 2 minutes**; the photo opens in the viewer without a download button (no context menu, no download/picture-in-picture controls for video), voice plays in a small player. A second open returns 410; the sender cannot open it. `php artisan chat:purge-view-once` (every minute) deletes the file 5 minutes after opening. (A screenshot or screen recording cannot be prevented in a browser.)
- **Also fixed:** Laravel file responses were sent with `Cache-Control: public` even though our header said `private`; attachments, link-preview images, stickers and view once media are now marked private so shared caches never keep them.
- Tests: `ViewOnceTest` (4: no links/preview details + normal route refused, receiver-only single open + signed link + expiry, file removal after 5 minutes, video/voice allowed but not documents + no forwarding), an extra private-cache assertion in `VideoMessageTest`, `view-once.test.js` (3). Checked in Chrome: bubbles for receiver/sender in opened/unopened states, "1" and HD switches in the tray, opening shows the photo without download and the bubble becomes "Opened".

### Phase 1 summary
- All 23 roadmap items (M1–M23) are done. Server: 283 PHPUnit tests; browser logic: 57 Vitest tests; each UI piece was checked in Chrome with test pages (the real app needs a login).
- **Before using on the live site:** run `php artisan migrate` (new tables/columns: reactions, stars, pins, link previews, stickers, poll votes, forward count, disappearing messages), `npm run build`, `php artisan config:cache`, and make sure the scheduler cron runs (new every-minute tasks: `chat:expire-messages`, `chat:purge-view-once`; daily `model:prune`).
- **Optional settings:** `CHAT_TENOR_KEY` (GIF search), `CHAT_LINK_PREVIEWS`, `CHAT_MAX_VIDEO_KB` (raise PHP/Nginx upload limits too).
- **Android app:** a new APK build is needed only for location sharing (new location permissions); everything else works through the existing app.

### UI check (M1–M10)
- The real app needs a login, so the new templates, forward dialog, reaction bar, pinned bar, search bar and message info were rendered with the production CSS in a local test page and checked visually. Fixed: the reaction pill on sent messages covered the time — the pill now sits on the bottom-left of every bubble.

### Verification (Phase 1 complete)
- ✅ PHPUnit — **283 tests passed** (199 after M10, 236 after M11, 241 after M12, 243 after M13–M15, 253 after M16, 260 after M17, 265 after M18, 269 after M19, 274 after M20, 279 after M21).
- ✅ Vitest — **57 tests passed** (`npm test`; 17 after M10, 22 after M11, 25 after M12, 29 after M13, 33 after M14, 36 after M15, 39 after M16, 41 after M17, 45 after M18, 48 after M19, 52 after M20, 54 after M21).
- ✅ `npm run build`.

### M23 — Paste a photo from the clipboard ✅
- Already supported by the composer (paste an image → attachment preview); marked done.

---

## Roadmap Phase 2 — Chat list ✅

Built one feature at a time from [`docs/FEATURE-ROADMAP.md`](docs/FEATURE-ROADMAP.md), each with PHPUnit tests, Vitest tests for browser logic and a check in Chrome with a test page.

### C1–C5 — Pin, mute, archive, mark unread/read, clear and delete ✅
- New table `chat_settings`: each person's own settings for a chat (the other person never sees them): `pinned_at`, `muted_until` (datetime — "always" is stored as the year 2999, beyond MySQL `timestamp`'s 2038 limit, found by a test), `archived_at`, `marked_unread`, `favorite_at`, `cleared_message_id` / `cleared_at`, `deleted_at`, `locked_at`.
- Endpoints: `PATCH /conversations/{id}/settings` (`pinned`, `archived`, `muted` = `8h`/`1w`/`always`/null, `unread`, `favorite`), `POST /conversations/{id}/clear` (`keep_starred`), `DELETE /conversations/{id}` (delete for me). Other tabs/devices of the same person are told with a `chat.settings` event. The chat list and chat payloads carry `settings`.
- **C1 Pin:** up to 3 pinned chats at the top (the 4th is refused with a clear message); archiving unpins.
- **C2 Mute:** 8 hours, 1 week or always. Muted chats still count their unread messages but create no notification, push or sound; they are left out of the page title / badge total; a bell-off icon and a grey unread badge show it. A timed mute ends by itself.
- **C3 Archive:** archived chats leave the main list for an **Archived** row at the top (count, or the number of unread archived chats) and stay archived when new messages arrive; "Unarchive" brings them back.
- **C4 Mark as unread / read:** "Mark as unread" puts a dot on the chat until it is opened; "Mark as read" reads all waiting messages (read receipts are sent, like opening the chat). The Unread filter includes marked chats.
- **C5 Clear chat** hides every message so far for me only (also from search, unread counts and the last-message preview; starred messages are unstarred unless "Keep starred" is chosen) and keeps the chat in the list, empty. **Delete chat** also removes it from my list (and unpins / unarchives it) until a new message arrives. Both work through one visibility rule on messages (`notClearedFor`), so every screen respects them. The other person's chat is untouched.
- UI: a chevron on each chat (hover) or right-click / long-press opens the chat menu (sheet on phones): Archive, Mute, Pin, Mark as read/unread, Clear chat, Delete chat. The open chat's menu also has Mute, Clear and Delete. Changes show at once and roll back if saving fails.
- Tests: `ChatListSettingsTest` (7: pin limit and privacy + event, mute without notification and expiry + always, archive stays archived + unpins, mark unread/read + cleared by opening, clear hides for me only and keeps the chat, clear keeping starred, delete until a new message), `chat-list.test.js` (4: order with pinned/archived/deleted/cleared chats, unread totals without muted/archived, ended mute, row flags). Checked in Chrome: pinned first, Archived row with unread count, muted and marked-unread flags, menu from the chevron and right-click, pin/archive/mute-always changes.
- Verification: PHPUnit **290 passed**, Vitest **61 passed**, build ✅.

### C6 — Favorites and your own lists ✅
- **Favorites:** "Add to Favorites" / "Remove from Favorites" in the chat menu (the `favorite` chat setting from C1–C5); a **Favorites** filter chip shows only those chats.
- **Own lists:** new tables `chat_lists` (per person, name up to 30 characters, unique per person, up to 20 lists, ordered) and `chat_list_items`. Endpoints `GET/POST /chat-lists`, `PATCH /chat-lists/{id}` (rename and/or replace the chats), `DELETE /chat-lists/{id}`; only the person's own chats can be added, and other people get "not found" for someone else's list. Other tabs/devices are told with a `chat.lists` event.
- UI: each list is a filter chip after Favorites, with a **+** chip to create one (name, search and tick chats). Right-click / long-press a list chip to edit or delete it (the chats themselves are not affected). "Add to list" in the chat menu ticks the lists a chat belongs to, or creates a new list with that chat. Empty filters explain how to fill them; the Archived row only shows under All; the filter row scrolls sideways on phones. If the selected list is deleted on another device, the list falls back to All.
- Tests: `ChatListsTest` (3: favourites are private, lists created/renamed/filled/deleted with only my chats and hidden from others, name and 20-list limits), `chat-lists.test.js` (4: All, Unread with marked chats, Favorites, list filters). Checked in Chrome: list and Favorites filters, New list dialog (name spaces collapsed, chosen chat saved, new list selected), Add to list from the chat menu, edit/delete dialog from right-click on a chip.
- Verification: PHPUnit **293 passed**, Vitest **65 passed**, Pint ✅, build ✅.
### C7 — Message yourself ✅
- A chat whose two participants are the same person (`user_one_id = user_two_id`); the conversation model no longer refuses it and `POST /conversations` with your own id opens it (always the same chat). Chat payloads carry `is_self`.
- Notes are stored as delivered and read at once, so they never count as unread and never ask for delivery receipts; there is no notification or push. They still reach your other tabs and the phone app in real time — message events now use one channel when sender and receiver are the same person (no double delivery).
- "Delete for me" on a note hides it completely (both the sender and receiver side are the same person).
- Not offered in a self chat: typing / recording indicators (server skips them), voice and video calls ("You cannot call yourself."), view once (the switch is hidden and the server sends the file normally), blocking yourself (already refused).
- UI: **"Your name (You) — Message yourself"** is the first row of the New chat panel (also found by searching "you" or your name). In the chat list and header the chat shows your name with "(You)", no online dot or last seen, and "Message yourself" under the name; call buttons and the Block item are hidden.
- Tests: `MessageYourselfTest` (5: one self chat per person and private, notes read at once without notification/unread and on one channel, delete for me, no typing/calls/view once, cannot block yourself), `message-yourself.test.js` (3: contacts row, list row without online dot, view once switch hidden). `ConversationTest` updated (self chats are now allowed; unavailable users are still refused).
- Verification: PHPUnit **298 passed**, Vitest **68 passed**, Pint ✅, build ✅.
### C8 — Invite friends ✅
- **Invite friends** in the New chat panel opens a sheet with the invitation ("Hi! I'm using One2One to chat and call for free. Join me: <link> My username is @you.") and WhatsApp, SMS, Email, Copy link and — in browsers with the Web Share API — More (the phone's share sheet). Inside the Android app WhatsApp/SMS/Email open the phone's own apps (the WebView hands these links to Android), so **no new APK is needed**.
- The link is `CHAT_INVITE_URL` (new, in `.env.example`; e.g. an APK or Play Store link) or, when empty, this site's sign-up page. It reaches the page as `chatConfig.invite.url`.
- **Phone contacts who are not on the app:** `POST /contacts/sync` now also returns `unmatched` — the positions of the sent phone-book entries whose numbers nobody is registered with (entries with your own number or no usable number are left out). The numbers are still **never stored**; the page keeps the list in memory for the session only. The New chat panel lists them under **"Invite to One2One"** (search by name or number) with an **Invite** button that opens WhatsApp or SMS addressed to that person. In the app the list is refreshed once per session when Contacts permission was already given; on the web it fills from the Contact Picker or a .vcf import.
- When several phone-book entries have the same registered number, all of them count as matched (none is offered for an invite); the first one still names the contact, as before.
- Tests: `InviteFriendsTest` (2: unmatched entries without own/short numbers or duplicates of registered numbers and nothing stored; invite link default and from config), `invite.test.js` (4: message text, WhatsApp/SMS/email links incl. local numbers, unique sorted invite list, escaped contact row). Checked in Chrome: Invite friends sheet, "Invite to One2One" rows, WhatsApp/SMS links addressed to the chosen contact.
- Verification: PHPUnit **300 passed**, Vitest **72 passed**, Pint ✅, build ✅.
### C9 — Chat lock ✅
- **Secret code** (4–8 digits, stored hashed in the new `users.chat_lock_pin`, never sent to the page): created the first time you choose "Lock chat"; changing it needs the account password. "Forgot code?" removes it with the account password, and every locked chat goes back to the chat list.
- **Locked chats folder:** a locked chat (`chat_settings.locked_at`) leaves the chat list, Archived and pins and waits in a **"Locked chats"** row at the top (only a number / unread count, never names). Opening the row asks for the code (`POST /chat-lock/unlock`, 5 tries a minute); going back locks it again (`POST /chat-lock/lock`). An unlock lasts `CHAT_LOCK_UNLOCK_MINUTES` (default 10, new in `.env.example`) in this browser session and is extended while the chats are used; the page also locks after 5 minutes without clicks or typing.
- **Enforced on the server, not just hidden:** while locked, the chat list returns the chat without the other person, last message or preview; its messages, the chat itself, search in it, attachments, reactions, stars and pins answer **423 "This chat is locked."** (conversation and message policies); starred messages and background sync leave it out. Opening `/chat/{id}` (e.g. from a notification) still loads the page and the chat asks for the code, then opens. The other person is not affected and is not told.
- **Notifications:** new messages in a locked chat create a notification and phone push that say only "New message" from the app name — no sender, avatar or text (notification centre, realtime, polling feed and Firebase push); in-page notifications do the same. The locked chats' unread messages are left out of the title/badge total. Works with the existing Android app — **no new APK** (fingerprint unlock would need one).
- UI: "Lock chat" / "Unlock chat" in the chat menu and the open chat's menu (not for "Message yourself"); locked chats are left out of search, forwarding and list pickers. The code dialog shows clear errors (wrong code, too many attempts, codes do not match).
- Tests: `ChatLockTest` (6: creating/changing the code, locked chat hidden until the code incl. list payload, 423s, sync, page, other person, relock and expiry; locking removes pin/archive and hides stars; private notifications and push; forgotten code with password; rate limit), `chat-lock.test.js` (4: list/archive/unread without locked chats, count-only row, folder opens only with a valid code and locks on going back, code created before the first lock). Checked in Chrome: Locked chats row, wrong/right code, folder with the locked chat and its menu, going back relocks, locking a chat from the list.
- Verification: PHPUnit **306 passed**, Vitest **76 passed**, Pint ✅, build ✅.

### Deploying Phase 2
- Run `php artisan migrate --force` (new tables `chat_settings`, `chat_lists`, `chat_list_items`; new column `users.chat_lock_pin`), then `npm run build` and `php artisan optimize`. Optional `.env`: `CHAT_INVITE_URL`, `CHAT_LOCK_UNLOCK_MINUTES`.
---

## Roadmap Phase 3 — Calls ✅

Built one feature at a time from [`docs/FEATURE-ROADMAP.md`](docs/FEATURE-ROADMAP.md), each with PHPUnit tests, Vitest tests for browser logic and a check in Chrome with a test page.

### K1 — Calls tab ✅
- **Calls** opens from the phone button in the chat list header, the ⋮ menu, or the new **Calls** tab in the phone bottom bar (now Chats · Calls · Contacts · You). A red badge counts missed calls not looked at yet; opening the tab clears it (the missed calls are also no longer unread in their chats).
- The list shows every call, newest first: name as saved in the phone book, a green incoming/outgoing arrow or a red missed-call arrow with the name in red, "Today, 11:40 AM · 12:34" (or "Not answered"), and a voice/video button to **call back**. Back-to-back calls with the same person, direction and outcome on the same day are grouped ("Sara Malik (2)"). Tapping a call opens the chat; right-click / long-press removes it (or the whole group); the bin in the header clears the call log. Loads 30 at a time while scrolling and refreshes when a call ends or a missed call arrives.
- Server: `GET /calls` (`before` paging, `unseen_missed`), `POST /calls/seen`, `DELETE /calls/{call}`, `DELETE /calls`. The log follows each call's history message, so removing a call deletes its chat bubble for me only, and "Clear chat" (C5) and locked chats (C9) apply to it; the other person keeps their log.
- Tests: `CallLogTest` (4: newest first with direction/missed/saved name and only my ended calls; Calls tab marks missed calls seen incl. chat unread; remove one / clear all for me only; paging, locked and cleared chats), `call-log.test.js` (2: grouping, detail text). Checked in Chrome: badge, grouped red missed calls, call back, open chat, remove a group.
- Verification: PHPUnit **310 passed**, Vitest **78 passed**, Pint ✅, build ✅.
### K2 — Voice call → video call ✅
- A connected voice call shows a **Video** button. Tapping it turns this phone's/computer's camera on: the call switches to the video layout (loudspeaker on in the Android app), the other person sees the video at once and gets a **"Sara turned on video · Turn on your camera"** button. Once a camera is on, the button is the usual Camera on/off, with Flip on phones.
- How: every call now has a video line from the start that sends nothing (the caller adds it, the callee answers it as send-and-receive), so a camera is added with `replaceTrack` — **no renegotiation, no reconnect, no break in the audio**. The other side switches when the video actually arrives (and also from a `media` signal and the call update), so it works even if one message is late.
- Server: `POST /calls/{call}/video` marks the ongoing call as a video call (409 if not in progress, 404 for strangers) and tells both sides; the call history then says "Video call". Works in the existing Android app — the camera permission is asked when needed and the ongoing-call service is updated to allow the camera in the background; **no new APK**.
- Tests: `CallTest::test_a_voice_call_in_progress_can_switch_to_video`, `call-video.test.js` (3: Video button only when connected, camera turned on with replaceTrack + speaker + media signal + server call, switch and invite when the other side turns video on). Real WebRTC check in Chrome with two connections in one page: a voice call set up the same way received video in both directions after `replaceTrack`, with signaling staying `stable` (no new offer/answer).
- Verification: PHPUnit **311 passed**, Vitest **81 passed**, Pint ✅, build ✅.
### K3 — Picture-in-picture ✅
- **Floating video window in the app:** minimising a video call (the ⤡ button, Esc, or Android back) keeps the other person's live video in a small window over the chat, with the call time, **Open call**, **Mute** and **End**. Drag it anywhere (it stays inside the screen and where you left it); tap the video to open the call again. Chats, lists and typing keep working underneath. Voice calls keep the small green "return to call" pill, which turns into the video window if the call switches to video (K2). When the call ends the window says "Call ended · 2:08" for a moment.
- **Browser picture-in-picture:** in browsers that support it (desktop Chrome, Edge, Safari) the call screen has a picture-in-picture button that floats the video over other tabs and apps; it stays open when the call is minimised and closes when the call ends. Chrome can also open it by itself when you switch tabs during a video call (Media Session "enterpictureinpicture").
- Android app: the in-app floating window works in the current app (**no new APK**). A floating window over *other* Android apps (pressing Home during a call) needs Android's native picture-in-picture, which would need a new APK — not built.
- Tests: `call-pip.test.js` (4: window kept on screen, minimise shows the window with the remote video + mute + tap to open, voice pill turns into the window on video, browser PiP button only when supported). Checked in Chrome with a live test video: floating window over a page, dragging without opening the call, real browser picture-in-picture entered from the button, still open after minimising, closed when the call ended.
- Verification: Vitest **85 passed**, Pint ✅, build ✅ (no server changes).
### K4 — Screen sharing ✅
- During a connected call, computers get a **Share** button: pick a whole screen, a window or a browser tab, and it is sent instead of the camera (voice calls too — the other side switches to video). A green **"You're sharing your screen · Stop"** bar shows at the top and your own preview shows the screen uncropped. Stopping (the button, the bar, or the browser's own "Stop sharing") goes back to the camera if it was on, or to no video.
- The other person sees the screen **whole (not cropped)**, with "Sara is sharing their screen" under the name, also in the floating window (K3).
- Works through the call's video line from K2 (`replaceTrack`), so there is no reconnect; the track is marked as detailed content so text and slides stay sharp. Closing the screen picker does nothing.
- Only where browsers allow it: Chrome, Edge, Firefox and Safari on computers. Phone browsers and the Android app can't share the screen, so the button is hidden there (Android would need native screen capture in a new APK).
- Fix found while checking: in video calls the call buttons jumped up under the name once the other person's video started; they now stay at the bottom.
- Tests: `call-screen-share.test.js` (4: button only where supported and not in the app, screen sent and camera restored when the browser's stop ends the track with media signals, cancelled picker, remote screen uncropped with label). Checked in Chrome with a test screen: Share → banner, uncropped preview, "Stop" on the banner back to the camera; buttons at the bottom.
- Verification: Vitest **89 passed**, build ✅ (no server changes).
### K5 — Call quality indicator and low data mode ✅
- Every 2 seconds during a call the app reads the connection's WebRTC statistics (round-trip time, packet loss in both directions, jitter). When the connection is bad for two readings in a row, a pill appears under "VOICE CALL / VIDEO CALL": yellow **"Weak connection · Use less data"** or red **"Poor connection · Use less data"**. It goes away when the connection recovers (a single bad reading is ignored, so it does not flicker).
- **Low data mode:** tapping the pill (or turning it on earlier) lowers what the call sends — video at half resolution, 15 fps, max 150 kbps; audio max 24 kbps — without reconnecting. The pill then shows "Low data mode · Turn off". The choice is remembered on this device for the next calls, and it applies when *either* person turns it on (sent to the other side in the `media` signal).
- No server changes; works in the Android app as is (**no new APK**).
- Tests: `call-quality.test.js` (4: reading stats with first-sample counters and loss from both directions, rating + no flicker, pill → low data limits on video/audio + remembered + turned off, following the other person's low data mode). Real check in Chrome with two connections: the app's stats reader worked on a real report (rated good on a local link), and low data mode through the app's own code set the encodings and the sent video dropped from 28 to 15 fps at a lower resolution.
- Verification: Vitest **93 passed**, build ✅.
### K6 — Group calls (mesh, up to 4 people) ✅
- **How to start:** during a connected one-to-one call tap **Add person** (above the call buttons) and choose someone; or in the Calls tab tap the people icon → **New group call**, tick up to 3 people and choose Voice or Video. More people can be added from the group call screen (up to 4 in total).
- **Ringing:** every person added gets a normal incoming call ("Group video call with Ayesha"), on open tabs and — through the existing push — on Android phones even when the app is closed; Accept / Decline / missed calls work as usual. **No new APK needed.**
- **Group call screen:** a grid with a tile for you and for everyone else (video or avatar, name, muted-microphone icon, "Ringing…/Connecting…/Reconnecting…"), the call time and number of people, and Speaker (app), Video/Camera, Flip, Mute, End and Add person. A voice group call switches to video when anyone turns their camera on (same no-reconnect method as K2). Minimising shows the "Group call · 2:15 · 3 people" pill; Android back and Esc minimise.
- **How it connects:** every device connects directly to every other device ("mesh", using the existing STUN/TURN servers); for each pair, the person who joined later sends the offer, so nobody collides. When someone leaves, the others stay connected; when fewer than two people remain (and nobody is still ringing) the call ends. Devices that stop reporting in for 90 seconds are removed.
- **Server:** new tables `call_rooms` and `call_room_participants`; `calls.call_room_id` (each invite is a normal call row) and `call_signals.call_room_id`. Endpoints: `POST /calls/{call}/participants` (one-to-one → group + invite), `POST /call-rooms` (start), `GET /call-rooms/{room}`, `POST /call-rooms/{room}/participants|leave|heartbeat|signals`, `GET /call-rooms/{room}/signals`; `call.room` realtime event. Hanging up an invite call (also from the phone's ongoing-call notification) leaves the group call. People in a group call are "busy" for other calls. Blocked people can't be added. `CHAT_CALL_MAX_GROUP` (default 4).
- Not in group calls yet: screen sharing, the floating video window and low data mode (they work in one-to-one calls). More than 4 people would need a media server (the LiveKit option).
- Tests: `GroupCallTest` (7: add person → room + ring with names + join order; 4-person limit, blocked and busy people, busy for other calls, strangers 404; room signals only to the right device and only to joined people; leaving keeps the others, the last two end it with history messages; declined invite ends a room with one person; start from Calls tab; vanished devices removed), `group-call.test.js` (5: mesh plan and offer rule, people to add, tiles incl. ringing + busy + routing of signals and hang up, one-to-one call moved into the group without ending it, group invite text). **Real WebRTC check in Chrome:** three instances of the app's `GroupCall` in one page, connected through a stand-in server — two people connected, the third joined and connected to both (as the offerer), turning her camera on sent video to both others, and when the first person left the other two stayed connected.
- Verification: PHPUnit **318 passed**, Vitest **98 passed**, Pint ✅, build ✅.
### K7 — Call links ✅
- **Calls tab → link icon → Call links:** create a **voice** or **video** call link (it is copied at once), copy or send it again (the phone's share sheet, or WhatsApp), or delete it. Up to 10 links per person.
- **Opening a link** (`/call/{token}`) opens the chat app and asks **"Join Ayesha's video call?"**; people who are not signed in sign in first and come back to it. Joining starts the call and **rings the link's owner** (also on their phone); anyone else who opens the same link while the call lasts joins it too (up to 4 people, as in K6). The owner can open their own link and wait ("Waiting for others to join…"). A call opened from a link keeps waiting while someone is still in it; after it ends the same link starts a new call. Deleted links say "This call link is no longer valid."
- Blocked people can't join a link's call; people already in a call are told so; a full call says "The call is full."
- Server: table `call_links`; `GET/POST /call-links`, `DELETE /call-links/{token}`, `GET /call/{token}` (page), `POST /call-links/{token}/join`. Group calls opened from a link remember the link (`call_rooms.link_token`).
- Tests: `CallLinkTest` (5: create/list/delete by owner only and deleted link page + 410; not signed in → login; opening a link starts the call, rings the owner, others join the same call, joining twice changes nothing; owner waits, call ends when the last person leaves and the link starts a new one; blocked/full/busy), `call-links.test.js` (4: message text, join prompt → join, invalid link, create/copy/delete). Checked in Chrome: join prompt with the owner's name and the address changed back to the chat page, Call links dialog.
- Verification: PHPUnit **323 passed**, Vitest **102 passed**, Pint ✅, build ✅.

### Deploying Phase 3
- `php artisan migrate --force` (new tables `call_rooms`, `call_room_participants`, `call_links`; new column `calls.call_room_id`; `call_signals.call_room_id` and `call_signals.call_id` now optional), then `npm run build` and `php artisan optimize` — `scripts/deploy.sh` does all of it. Optional `.env`: `CHAT_CALL_MAX_GROUP` (default 4). No new APK.
---

## Roadmap Phase 4 — Groups ✅

Built from [`docs/FEATURE-ROADMAP.md`](docs/FEATURE-ROADMAP.md), each feature with PHPUnit tests, Vitest tests for the browser code and a check in Chrome with a test page.

### How groups are stored (all of Phase 4)
- `conversations.type` is `direct` (the two people in `user_one_id` / `user_two_id`, as before) or `group` (name, description, icon, creator, settings; no user ids). Existing chats keep working unchanged.
- `conversation_members`: who is or was in a group, `admin` / `member`, when they joined and left. `visible_from_message_id` / `visible_until_message_id` limit what a person sees to the time they were in the group (people added later don't see older messages; people who left keep what they saw). `last_read_message_id` gives the unread count.
- `message_receipts`: per person delivered / read times of group messages. When everyone has received / read a message, the message's own `delivered_at` / `seen_at` are set, so the sender's ✓ ✓✓ blue ✓✓ work as in one-to-one chats. `message_hides`: "Delete for me" of someone else's group message. Group messages have no `receiver_id`.
- The message visibility and unread rules (`visibleTo`, `unreadFor`) cover groups, so history, search, starred, pins, sync, clear chat (C5), locked chats (C9) and disappearing messages work in groups without separate code.

### G1 — Creating a group ✅
- **New group** in the ⋮ menu and at the top of the Contacts panel: tick people → name (required), optional icon and description → **Create group**. Up to 256 people (`CHAT_GROUP_MAX_MEMBERS`).
- Chat list: group icon/initials, "Sara: Dinner at 8?" (names as saved in your phone book), "Hina is typing…". Chat header: group name and "You, Ayesha, Bilal…"; tap it for **Group info**. Message bubbles show who wrote them (coloured name on the first bubble of a run). Notices in the chat are worded for the reader: "You created group "Family"", "Sara added you, Bilal".
- Group info: icon, name, description, created by/when, members with "Group admin" badges, **Add members**, change icon/name/description (with notices for everyone).
- Messages reach everyone in the group instantly; typing and recording show to everyone else; muted members get no notification; "Delete for me" works per person. Phones (existing APK) show the group name and icon with "Ayesha Baji: Kal milte hain". Call buttons in a group start a group call with its people (K6), choosing up to 3 when the group is bigger. People not in a group get "not found".
- Server: `POST /groups`, `POST /groups/{id}` (info/icon), `POST /groups/{id}/members`; `group.updated` realtime event; chat payloads have `type` and `group` (members when one chat is opened); messages have `sender_name`.
- Tests: `GroupChatTest` (8), `groups.test.js` (5: notices for the reader, list preview and typing name, sender label, header summary, group info for members/admins/former members). Checked in Chrome with a test page: list, header, labelled bubbles, notices, group info panel and member menu. Fixed while checking: the group icon in group info was squeezed.

### G2 — Group admins ✅
- Admins open a member in Group info: **Make group admin**, **Dismiss as admin**, **Remove**. The creator can't be removed or dismissed by others. Removed people see the chat up to their removal, read-only ("You can't send messages to this group because you're no longer a member"), and can be added back.
- Server: `PATCH /groups/{id}/members/{user}` (role), `DELETE /groups/{id}/members/{user}`. Tests: `GroupAdminTest` (admins, removing).

### G5 — Group settings ✅
- Admins: **Send messages — only admins** (everyone else sees "Only admins can send messages to this group"), **Edit group info — only admins** (name, icon, description, adding people, pinning messages and disappearing messages become admins-only). Each change leaves a notice.
- Server: `PATCH /groups/{id}/settings`. Tests: `GroupAdminTest::test_only_admins_can_send_or_edit_info_when_the_group_says_so`.

### G8 — Exit / delete group ✅
- **Exit group** (Group info, chat menu, chat list menu): "Ayesha left"; if the last admin leaves, the longest-standing member becomes admin; when everyone has left the group ends. A group you are still in must be exited before "Delete chat".
- **Delete group for everyone** (admins): "Ayesha deleted this group", everyone is removed, nobody can send; people keep what was sent until they delete the chat.
- Server: `POST /groups/{id}/leave`, `DELETE /groups/{id}`. Tests: `GroupAdminTest` (exit + next admin + delete chat after exit, delete for everyone).
- Verification (G1, G2, G5, G8): PHPUnit **336 passed**, Vitest **107 passed**, Pint ✅, build ✅.
### G3 — Invite link / QR ✅
- Admins: Group info → **Invite via link or QR code**: the link (`/join/{token}`), its **QR code** (drawn in the app with the `qrcode-generator` package, MIT, no dependencies), **Copy link**, **Share link** (phone share sheet or WhatsApp) and **Reset link** (the old link stops working; people already in the group stay).
- Opening a link (or scanning the QR) opens the app with the group's icon, name, member count and description and **Join group**; people not signed in sign in first and come back to it. Members go straight to the chat. Dead links (reset, deleted group) say so; full groups say "This group is full." Joining leaves "Sara joined using this group's invite link".
- Server: `GET /groups/{id}/invite`, `POST /groups/{id}/invite` (reset), `GET /join/{token}` (page), `POST /join/{token}`. Tests: `GroupInviteTest` (4: admins only + reset kills the old link, preview + join once, sign in first, full and deleted groups), `group-invite.test.js` (4: QR drawn, link dialog + reset, join offer → join → open, members and dead links). Checked in Chrome: link dialog with the QR code (Chrome on Windows has no QR reader, so the code was checked by eye, not by scanning).
### G4 — @mentions ✅
- In a group, typing **@** in the message box lists the people in the group (names as saved in your phone book; you and people who left are not listed). Arrow keys + Enter/Tab or a tap inserts "@Sara Malik"; the rest of the list narrows as you type. Works for photo/video captions too.
- Mentions show in the message in the app colour (your own name highlighted), and tapping one opens a chat with that person. Chats where you were mentioned and haven't read it show an **@** badge.
- Mentioned people are notified **even if they muted the group** ("Ayesha mentioned you in Family"); a reply to someone's message also reaches them in a muted group.
- Server: `mentions: [{id, name}]` on sending; only people currently in the group, never yourself, and only names that really appear in the text are kept (`attachment_meta.mentions` / `mention_ids`). Chats have `unread_mentions`.
- Tests: `GroupMentionTest` (2: which mentions are kept; @ badge, notification in a muted group, cleared when read, replies), `mentions.test.js` (5: "@word" detection, suggestions, mentions kept on send, highlighting outside HTML tags, @ badge). Checked in Chrome with a test composer: "@bi" → list → Enter inserts "@Bilal Bhai", "@sa" → Tab, Enter sends with both mentions and they are highlighted.
### G6 — Read by / Delivered to ✅
- **Message info** on your own group message lists **Read by** (with times, newest first), **Delivered to** and **Waiting** (not received yet), with names as saved in your phone book. One-to-one chats keep the simple Sent / Delivered / Read list.
- The ✓ / ✓✓ / blue ✓✓ on a group message change when *everyone* in the group has received / read it (based on the per-person receipts from G1). People who leave without receiving a message no longer hold it back.
- Server: `GET /messages/{message}/receipts` (only the sender, only group messages). Tests: `GroupReceiptTest` (read / delivered / waiting with saved name, only the sender, not for one-to-one messages, someone leaving), `message-receipts.test.js` (3: sorting, group message info, one-to-one list unchanged).
### G7 — Reply privately ✅
- On someone else's message in a group, the message menu has **Reply privately**: it opens your chat with that person (starting it if needed) with the group message quoted ("Replying to Sara · Family"). The quote in the private chat reads "Sara · Family" and tapping it opens the group at that message. The other people in the group see nothing.
- Server: `reply_to_id` may point to a group message when you send it in the one-to-one chat with the person who wrote it, the message was sent while you were in the group and it isn't deleted for you (not your own messages, not other chats). Quotes carry `conversation_id` and `group_name`.
- Tests: `GroupReplyPrivatelyTest` (2: quote with group name seen by both and not by others; wrong chat, never in the group, own message, deleted for me), `reply-privately.test.js` (2: quote with group name + jump to the group, menu item → opens the chat with the writer → reply mode → payload).
- Verification (G3, G4, G6, G7): PHPUnit **345 passed**, Vitest **121 passed**, Pint ✅, build ✅.
### G9 — Broadcast list ✅
- ⋮ menu → **New broadcast**: tick at least 2 people → the list opens like a chat (megaphone icon, "3 recipients" or its name; header lists the recipients). Every message sent there (text, photos, files, polls, locations…) is copied into **each recipient's own chat with you**, so they don't see each other and their replies come to your one-to-one chat. Blocked people and deactivated accounts are skipped.
- The list shows ✓✓ / blue ✓✓ when every copy has been delivered / read, and **Message info** lists Read by / Delivered to from the copies. **Delete for everyone** in the list deletes the message in every chat. No calls, typing, reactions or edits in a list.
- **Broadcast list info** (tap the header): rename, **Edit recipients** (current ones ticked), **Delete broadcast list** (messages already sent stay in each chat). Only the owner can see a list.
- Server: `conversations.type = broadcast` (owner is the only member), `broadcast_recipients`, `messages.broadcast_message_id`; `POST /broadcasts`, `PATCH /broadcasts/{id}`, `DELETE /broadcasts/{id}`. Copies are made after the response (`FanOutBroadcast`) and never twice for the same person. `CHAT_BROADCAST_MAX_RECIPIENTS` (default 256).
- Tests: `BroadcastListTest` (3: create/rename/recipients/owner only/delete; copies in each chat incl. photos, blocked skipped; ticks and read-by from copies, no calls/reactions/edits, delete for everyone deletes copies), `broadcasts.test.js` (4: names, megaphone avatar, info + edit recipients ticked, delete list).### G10 — Communities ✅
- ⋮ menu → **Communities** opens a sidebar view with your communities (groups · members). **New community**: name, description, icon and, optionally, groups you are an admin of. Every community gets an **Announcements** group where only admins post; everyone in any of its groups is in the community and gets the announcements.
- Community page: Announcements, **Groups you're in** (Open) and **Groups you can join** (Join — "Bilal joined from the community"). Admins also get **New group**, **Add existing groups**, **Invite via link or QR code** (same dialog as groups, `/community/{token}`), **Edit community** and **Delete community** (groups become normal groups again, announcements end). Everyone can **Exit community** (leaves the announcements and all its groups; the last admin must hand over first). Group info shows a chip with the community name that opens it.
- People added to a community group later join the community automatically; admins removing someone from the community removes them from every group of it. Groups can be in one community only (max 50 groups).
- Server: `communities` table, `conversations.community_id` + `is_announcement`; `GET/POST /communities`, `GET/POST/DELETE /communities/{id}`, `POST …/leave`, `DELETE …/members/{user}`, `POST …/groups` (new group), `POST/DELETE …/groups/{group}` (link/unlink), `POST …/groups/{group}/join`, `GET/POST …/invite`, `GET/POST /community/{token}`.
- Tests: `CommunityTest` (3: announcement group + members from its groups, only admins post, later members join; create/link/unlink/join groups with the rules; invite link, leave leaves groups, remove, delete), `communities.test.js` (5: linkable groups, list + detail sections, member vs admin tools, join/invite dialog/open announcements, join from an invite link). Checked in the browser harness: list, community page, Join, community invite QR dialog, New community dialog.
### G11 — Channels ✅
- ⋮ menu → **Channels** opens a sidebar view: **Create channel**, **Channels you follow** (latest update, unread count, "Admin" badge) and **Find channels** with search by name or description (most followed first, follower counts like "1.5K followers") and a **Follow** button. Tapping a channel shows a preview first: icon, description, followers and its latest updates with reaction counts.
- A followed channel sits in the chat list like any chat. Only its admin posts (text, photos, files, polls, locations…); followers get a bar "Only channel admins can post updates", can **react** and **vote**, star and forward updates, and see old updates too. No calls, typing, read receipts, message info or reply; pinning is for admins. Updates never show who wrote them and don't send notifications (the unread count still shows).
- **Privacy:** followers never see each other and admins don't see them either — reactions and poll votes come back as counts, with only your own reaction/vote marked (the app keeps your choice when live updates arrive without names).
- **Channel info** (tap the header): followers, description, **Share channel link** (link + QR, `/channel/{token}` opens the preview), admins **Edit channel** / **Delete channel** (removes it and its updates for everyone), followers **Unfollow channel** (also in the chat menu; "Delete chat" asks to unfollow first).
- Server: `conversations.type = channel` (admins and followers in `conversation_members`, followers see from the first update, no receipts), `ChannelService`, `ChannelController`; `GET/POST /channels`, `GET/POST/DELETE /channels/{id}` (preview for anyone / edit / delete), `POST/DELETE /channels/{id}/follow`, `GET /channel/{token}`. Message payloads hide sender names and other people's reaction/vote ids in channels (`ConversationTypes`, remembered per request). No new migration.
- Tests: `ChannelTest` (3: create, search, preview for strangers, follow sees history without unread, only admins post, no sender name/notifications, seen clears unread, no calls/receipts, link page; reactions and votes show counts with only your own id, admins-only pin; edit/delete permissions, delete chat needs unfollow, admins can't unfollow, unfollow removes it, delete for everyone), `channels.test.js` (6: short counts, keep my reaction/votes on nameless updates, counts from totals, sidebar lists + follow, preview + follow, info for followers/admins + share link without reset). Checked in the browser harness: channels sidebar, preview dialog, channel info, channel link dialog.
- Verification (Phase 4 complete): PHPUnit **354 passed**, Vitest **136 passed**, Pint ✅, build ✅.
- Deploy notes: run `php artisan migrate` (groups, broadcast lists, communities tables) and `npm ci && npm run build` (new `qrcode-generator` package). No new APK needed.

## Roadmap Phase 5 — Status ✅
Status updates live 24 hours. **"My contacts"** means the people you saved in your phone book **and** the people you have a one-to-one chat with (web users often have no phone-book contacts). People who block each other never see each other's updates.

### Storage (Phase 5)
- `statuses` (type text/image/video, body, background, font, attachment + meta on the private `chat` disk, `privacy` + `privacy_user_ids` copied from the owner's setting when posted, `expires_at`), `status_views` (viewed_at, reaction), `status_privacy_users` (except / only lists), `status_mutes`, `users.status_privacy`. Migration `2026_09_21_000001_create_statuses` (rollback checked).
- `php artisan chat:expire-statuses` (scheduled every 5 minutes) removes updates older than a day and their files. `CHAT_STATUS_LIFETIME_HOURS` (24), `CHAT_STATUS_MAX_VIDEO_SECONDS` (60).
- Live updates: `status.updated` (to everyone who may see a new/deleted update, so their list refreshes and the Status dot lights up) and `status.viewed` (to the owner, view count).

### S1 — Text / photo / video status ✅
- New **Status** button in the sidebar header (and an **Updates** tab on phones) with a dot when there are new updates. The Status view shows **My status** (ring with one segment per update, "2 updates · 10 minutes ago"), **Recent updates**, **Viewed updates** and **Muted updates**.
- **Text status**: full-screen editor, tap the palette to change the background (8 colours) and **T** to change the font (5 styles); the text shrinks as it gets longer. **Photo / video**: pick a JPG/PNG or a video (up to 60 s, checked in the browser and on the server), preview it, add a caption, post. Videos send a poster frame.
- **Viewer**: progress bars, tap right/left (or arrow keys) for next/previous, press and hold or the pause button to pause, videos play for their length with a sound toggle; it starts at the first update you haven't seen and carries on with the next person in the same list. Delete your own update from the viewer.
- Server: `GET /statuses` (my updates + updates grouped by person, not seen first), `POST /statuses` (text, background, font / attachment, caption, thumbnail, duration), `DELETE /statuses/{id}`, `GET /statuses/{id}/media` (only for people who may see it).
- Tests: `StatusTest` (2: text/photo/video posted and validated, contacts and chat partners see them, strangers and blocked people don't, media protected, gone after 24 h and files removed by the command; only the owner deletes).

### S2 — Kisne dekha ✅
- Opening someone's update marks it seen (once). On your own update the viewer shows **👁 N views**; tap it for **Viewed by** with names, time and their reaction. The count updates live.
- Server: `POST /statuses/{id}/view`, `GET /statuses/{id}/viewers` (owner only).
- Tests: `StatusViewersTest` (2: one view per person, owner's own views and strangers don't count, live event, viewers newest first, only the owner sees them; people not seen yet come first).

### S3 — Status privacy ✅
- **Status privacy** (lock button or the link at the bottom of the Status view): **My contacts**, **My contacts except…** or **Only share with…**, choosing people from contacts and chats. The choice applies to updates posted from then on, like WhatsApp; "Only share with" needs at least one person.
- Server: `GET/PUT /status/privacy`.
- Tests: `StatusPrivacyTest` (except hides new updates but not earlier ones, only-share reaches exactly the chosen people, validation).

### S4 — Status ka jawab aur reaction ✅
- In the viewer: quick reactions (😍 😂 😮 😢 👏 🔥) and a **Reply** box. Both arrive in the one-to-one chat with the owner, with a quote of the update ("Ayesha · Status", its text or a small picture while it lasts, "Status reaction" for reactions). The reaction is also shown in the owner's viewers list; the same emoji is sent only once.
- Server: `POST /statuses/{id}/reply`, `POST /statuses/{id}/react`; messages keep a snapshot in `attachment_meta.status` and the API returns `status_quote` (`available`, `thumbnail_url` only while the update lasts). Chat list preview: "Reacted ❤️ to a status".
- Tests: `StatusReplyTest` (2: reply quote with picture, chat and unread, not to your own or others' private updates, quote stays after expiry without picture; reactions once per emoji, preview text, reaction in viewers, blocked people can't react).

### S5 — Mute status ✅
- The bell button in the viewer mutes/unmutes a person: their updates move to the collapsible **Muted updates** part and don't light up the Status dot. They are never told.
- Server: `POST/DELETE /statuses/mutes/{user}`.
- Tests: `StatusMuteTest` (muted flag, unmute, can't mute yourself). Frontend: `status.test.js` (10: time labels, ring segments, sections, chat bubble quote, Status view + dot, viewer from first unseen → next person → sections update, reply/react/mute, viewers list + live count + delete, text composer colour/font, privacy save). Checked in the browser harness: Status view, photo and text viewer, viewers sheet, text composer, privacy dialog, status quotes in bubbles (fixed the quote's contrast in my own bubbles and centred the composer text).
- Verification (Phase 5 complete): PHPUnit **362 passed**, Vitest **146 passed**, Pint ✅, build ✅.
- Deploy notes: `php artisan migrate` (statuses tables) and `npm run build`; the scheduler (cron) must run for expired updates to be cleaned up. No new APK needed.


## WhatsApp-style UI (before Phase 6) ✅
The whole app now looks like WhatsApp — WhatsApp Web on computers, the WhatsApp app on phones and in the Android app (it shows the same site, so no new APK) — in light and dark.
- **Colours and type:** WhatsApp's palette in `theme.css` (green `#00a884`, chat wallpaper `#efeae2` / `#0b141a`, outgoing bubbles `#d9fdd3` / `#005c4b`, blue ticks `#53bdeb`, grey panels and icons) and system fonts (Segoe UI / Roboto / SF) instead of Inter. The chat wallpaper is our own doodle pattern (not WhatsApp's image); name, logo and favicon stay our own, now in green.
- **Computers:** a navigation rail on the left (Chats with unread count, Status with a dot, Channels, Communities, Calls with missed count, then Starred, theme, Settings and your photo), a "Chats" header with new chat, notifications and the menu (New group, New community, New broadcast, Starred messages, Theme, Settings, Log out), search pill, filter chips, flat chat rows with separators, grey chat header, wallpaper, WhatsApp bubbles with the small tail on the first message of a run, grey composer bar, and a "One2One Chat Web" welcome screen with a green bottom line.
- **Phones / Android app:** the app name in green at the top, search pill and chips, a rounded green FAB, bottom tabs **Chats · Updates · Communities · Calls** with WhatsApp's pill highlight, white chat header, floating composer over the wallpaper with a round green mic/send button. Each section has its own header; Updates (Status) now also links to Channels, like WhatsApp's Updates tab.
- Also restyled: menus, dialogs, toasts, reply/status quotes, link cards, files, voice notes and polls inside the new bubbles, side panels (group/channel info), login/register (green band and white card like WhatsApp Web's login), settings and error pages.
- Code: `resources/css/whatsapp.css` (loaded last), tokens in `theme.css`, rail/header/tabs/welcome in `chat/index.blade.php`, the rail and tabs open every section (`bindMobileNav`), every unread badge updates.
- Checked in the browser with the real page rendered (desktop light/dark, phone list and chat, login, settings). Tests: PHPUnit **362 passed**, Vitest **146 passed**, build ✅.


## Roadmap Phase 6 — Privacy and security ✅ (P11 postponed)
Decisions: **P11 end-to-end encryption is postponed** (it would break server search, link previews, notification text, broadcast copies and history on new devices; to be planned as its own project). **P8 app lock** will use the phone's fingerprint / face / screen lock through native code, so it needs a new APK.

### Storage (Phase 6)
- Migration `2026_09_22_000001_create_privacy_and_security` (rollback checked): users get `about`, `last_seen_privacy`, `online_privacy`, `photo_privacy`, `about_privacy` (default everyone, so nothing changes until someone chooses), `read_receipts` (default on), `two_step_pin`, `two_step_enabled_at`; new tables `user_reports`, `trusted_devices`, `login_links` (used by P7 / P10). `expires_at` uses a default so MySQL strict mode accepts the table.
- `PrivacyService` and `ReadReceiptService` remember their checks for one request (scoped services, cleared after every request).

### P1 — Last seen / online privacy ✅
- Settings → **Privacy**: **Last seen** Everyone / My contacts / Nobody and **Online** Everyone / Same as last seen. "My contacts" = people you saved and people you have a one-to-one chat with (same as status). Like WhatsApp, if you share your last seen with nobody you don't see anyone's; blocked people never see it.
- Applied everywhere a user is sent to someone else (`UserResource`: chat list, search, contacts, groups, calls, status viewers…), in the polling sync and in `/users/online` (people who hide their online status are left out).
- Live presence no longer uses the global `online` presence channel (which showed every online user to everyone): `UserPresenceChanged` now goes on private channels, only to chat partners and people who saved the user, and each person gets only what they may see (online, last seen, both or nothing). The chat shows nothing instead of "offline" when last seen isn't shared.
- Tests: `LastSeenPrivacyTest` (4: everyone / contacts / nobody incl. who counts as a contact, online "same as last seen", online list; hiding yours hides others', blocks hide everything; live presence reaches only allowed people with the right fields; saved from settings with validation).

### P2 — Profile photo and About privacy ✅
- Settings → Privacy: **Profile photo** and **About** Everyone / My contacts / Nobody. People who may not see the photo get initials; the About is left out. Phone notifications and the notification centre also leave out a hidden photo.
- Note: photos are public storage files, so someone who already has the file address could still open it; the app just never gives it to people who may not see it.
- Tests: `ProfilePrivacyTest` (3 incl. About) — photo and About hidden by setting, own details still visible, notification without a hidden photo.

### P3 — Read receipts off ✅
- Settings → Privacy: **Read receipts** switch. In one-to-one chats, if either person turned it off nobody sees blue ticks (read shows as delivered, no "read" live event, no read time in message info). Messages are still marked read on the server, so unread counts keep working. Group chats always keep receipts, like WhatsApp.
- Tests: `ReadReceiptsTest` (2: delivered instead of read both ways, unread still cleared, back to read when both turn it on; groups unaffected).

### P4 — About (profile status text) ✅
- Settings → Profile: **About** (up to 139 characters, spaces tidied). Shown in the new **Contact info** panel: tap the header of a one-to-one chat (or ⋮ → Contact info) for the photo, name (with the profile name when you saved a different one), @username, online / last seen, About, and **Block / Unblock** and **Report**.
- Tests: in `ProfilePrivacyTest`; frontend `contact-info.test.js` (3: panel shows About / presence and hides what isn't shared, only for one-to-one chats, privacy choices save and roll back on error).
- Bug found by the new frontend test and fixed: an edit script had turned `$$(` into `$(` in `ui/settings.js` (a `$$` in a JavaScript replacement means one `$`); edit scripts now use replacement functions.

### P5 — Blocked contacts list ✅
- Settings → **Blocked contacts (N)**: everyone you blocked (with the name you saved), **Unblock**, and **Block someone** with a search over people you chat with. The Privacy tab shows how many people you blocked with a link to the list.
- Tests: `BlockedContactsTest` (list, block from settings, count, unblock; strangers aren't offered).

### P6 — Report user ✅ (server + chat dialog)
- In a one-to-one chat: ⋮ → **Report** or Contact info → Report. Choose a reason (Spam / Abusive or harassing / Fake account or scam / Something else), add a note, and **Also block** (on by default). The last 5 messages from that person in the chat go with the report (like WhatsApp); they aren't told. Reporting the same person again within a day updates the open report; only the chat between the two can be attached.
- Admin panel: **Reports** in the menu (with the number of open reports): Open / Reviewed / Dismissed lists; a report page with the reported account (status buttons to suspend), reason, reporter, note, the attached messages, other reports about them, an admin note, and **Mark reviewed / Dismiss / Reopen**. The admin privacy note now says reports only include messages the reporter chose to send.
- Server: `POST /users/{user}/report`, `GET /admin/reports`, `GET/PATCH /admin/reports/{report}`; `UserReport`, `ReportService`, `ReportController`.
- Tests: `ReportUserTest` (2: validation, can't report yourself, last 5 messages attached, also block, repeat updates, other chats never attached; only admins see and review reports). Frontend: the report dialog is covered in `security.test.js`.

### P7 — Two-step verification ✅
- Settings → Password & security → **Two-step verification**: turn on with a 6-digit PIN (typed twice) and your current password; change the PIN; **Forget browsers** (every browser asks again); turn off with your password.
- Signing in with the password on a browser that hasn't passed the PIN shows **Two-step verification** (enter PIN). The right PIN signs in (with "remember me" if it was ticked) and remembers this browser (encrypted cookie + `trusted_devices` row, 2 years). Wrong PINs: 5 tries, then a wait. **Forgot PIN?** emails a signed link (60 minutes) that turns two-step verification off. A wrong password never reaches the PIN step.
- Signing in now checks the password first and only signs in afterwards, so asking for the PIN never breaks "remember me" on other devices. The browser that turns two-step on, and a computer linked from the phone (P10), are trusted.
- Server: `TwoStepService`, `Auth\TwoStepController`, `TwoStepResetNotification`; `GET/POST /login/verify`, `POST /login/verify/forgot`, signed `GET /two-step/reset/{user}`, `POST/PUT/DELETE /settings/two-step`, `DELETE /settings/two-step/devices`.
- Tests: `TwoStepVerificationTest` (5: turning on needs a matching 6-digit PIN and the password, PIN hashed, browser trusted; a new browser is asked and trusted after, remembered browser isn't asked; tries limited, wrong password never reaches the PIN; forgot PIN email + signed link turns it off; change, forget browsers, turn off). Existing login tests still pass.

### P9 — Active sessions ✅
- Settings → Password & security → **Where you're signed in**: each browser / phone ("Chrome on Windows", "One2One Chat app on Android"), its network address and when it was last active, **This device**, **Log out** for the others and **Log out of all other devices**. The same list is in the app under ⋮ → **Linked devices**.
- Session ids are never shown (each session is identified by a hash). Signing a device out deletes its session, stops that phone's notifications (device token) and gives your account a new "remember me" token (this device gets a fresh cookie).
- Server: `SessionService`, `SessionController`, `App\Support\UserAgent`; `GET /settings/sessions`, `DELETE /settings/sessions/{key}`, `DELETE /settings/sessions/others`.
- Tests: `ActiveSessionsTest` (listed without ids, old sessions left out, phone signed out with its notifications and remember token, can't touch other people's sessions, log out of all others).

### P10 — Linked devices (QR login) ✅
- The login page on a computer shows **Log in with your phone**: steps, a QR code and a short code (e.g. `K7P2-M9QX`), valid for 3 minutes with **Get a new code** after. On the phone: ⋮ → **Linked devices** → **Link a device** → scan the QR code (camera; phones without QR scanning type the code), see "Link Chrome on Windows? From …", approve — the computer signs in by itself. Opening the QR link directly on a signed-in phone asks the same.
- Safety: the QR code carries only a link token; the computer is signed in only by the page that holds the link's secret, so a photo of the QR code can't be used to sign in. Single use, 3-minute expiry, rate limits. A computer linked this way isn't asked for the two-step PIN (it was approved from the phone).
- Server: `LoginLink`, `LinkedDeviceService`, `LinkedDeviceController`; `POST /login/qr`, `POST /login/qr/{token}/status`, `GET /link-device/{token}`, `POST /linked-devices/lookup`, `POST /linked-devices/approve`. The `login_links.session_id` column was renamed to `secret_hash` before anything was released.
- Tests: `LinkedDeviceTest` (2: QR alone signs nobody in, lookup shows the device, code approves, computer signs in once; guests can't approve, two-step accounts aren't asked for the PIN and the computer is trusted, expired codes). Frontend `security.test.js` (6: report dialog; QR and code reading; sessions list, sign out, link after confirming; link opened from the QR URL; login page shows the QR code and signs in when approved; expired code → new code).

### P8 — App lock ✅ (needs the new APK)
- In the Android app: Settings → Privacy → **App lock** (only shown inside the app): turn on (asks for the fingerprint / face / phone screen lock first) and choose **Immediately / After 1 minute / After 30 minutes**. The app shows a lock screen when it opens or comes back after that time; **Unlock** uses the phone's fingerprint, face or its own PIN / pattern. Phones without a screen lock are told to set one; older app versions say to update.
- Native: `NativeAppPlugin` gets `getAppLockStatus` and `unlockApp` (AndroidX `BiometricPrompt`, fingerprint/face with the device credential as fallback), dependency `androidx.biometric:biometric:1.1.0`. Web: `resources/js/native/app-lock.js` (settings stay on the phone).
- New debug APK built: `mobile/android/app/build/outputs/apk/debug/app-debug.apk` (6.8 MB). Note: Capacitor 8 needs Java 21+; the default `C:\Android\jdk` is 17, so the build used Android Studio's JBR (`JAVA_HOME=C:\Program Files\Android\Android Studio\jbr`).
- Tests: `app-lock.test.js` (4: settings and when to lock; locks on open and on return, unlocks; off / signed out / unsupported; turning on asks for the fingerprint first). The fingerprint prompt itself needs a real phone to try.

### Checked in the browser (Phase 6)
Rendered pages: Settings → Privacy (with the app lock row) and Password & security (sessions, two-step), the login page with the QR panel, the PIN page, an admin report; in the chat: Contact info, the Report dialog, Linked devices and the scanner's type-the-code mode. Fixed: privacy dropdowns had no arrow.

### Verification (Phase 6 complete)
PHPUnit **382 passed**, Vitest **159 passed**, Pint ✅, build ✅, debug APK ✅. Deploy: `php artisan migrate` and `npm run build` (deploy script does both); install the new APK for app lock (everything else works with the old APK). Also Laravel's mailer must be set up on the server for "Forgot PIN?" emails.

## Roadmap Phase 7 — Account ✅
Decision: SMS works with **any provider**: `SMS_DRIVER=log` (texts go to the log, for testing), `twilio`, or `http` for any SMS gateway with a web API (its URL, field names, extra fields / headers and a success text are set in `.env`). In production the log driver counts as "no SMS", so phone login stays hidden until a real gateway is set up.

### Storage (Phase 7)
- Migration `2026_09_23_000001_create_account_features` (rollback checked): `users.phone_verified_at`, `users.qr_token` (unique), table `otp_codes` (number, purpose login / change_number, user, keyed hash of the code, tries, expiry, used time). Codes that expired more than a day ago are removed daily (`prune-otp-codes` in the scheduler).
- `config/services.php` → `sms`; `.env.example` has an SMS section.

### A1 — Log in with phone number + SMS code ✅
- Login page: **or → Log in with phone number** (shown only when SMS is available). Enter the number (any format, e.g. `0092 300 1234567`) → **Enter the code** page → the right 6-digit code signs in without a password ("Remember me" works). Accounts with two-step verification are still asked for the PIN on a new browser. The first SMS login marks the number verified.
- Safety: codes last 5 minutes, work once, 5 wrong tries per code; **Send again** after 60 seconds (countdown on the page), 5 codes per number per hour, 20 per network address per hour, plus a route limit. Only an HMAC of the code is stored. A number without an account (or a suspended one) gets no SMS but sees exactly the same pages and errors, so nobody can check which numbers have accounts. A failed SMS shows "We couldn't send the SMS right now" and can be retried straight away. The SMS ends with the `@host #code` line so phones can fill the code in (WebOTP, `autocomplete="one-time-code"`).
- Server: `SmsService` (log / Twilio / generic HTTP), `OtpService`, `OtpCode`, `Auth\\PhoneLoginController`; `GET/POST /login/phone`, `GET/POST /login/phone/code`, `POST /login/phone/resend`; limiter `otp`. Frontend: `resources/js/auth/otp.js` (countdown + WebOTP).
- Tests: `PhoneLoginTest` (6: SMS login with a differently written number, code used once, remember me; no account / suspended looks the same with no SMS; wrong codes limited and codes expire; send again waits a minute, replaces the code, 5 per hour; two-step PIN still asked; failed SMS and no gateway = no phone login), `SmsServiceTest` (5: which gateways are ready incl. production + log; log; Twilio with Messaging Service; HTTP gateway with its own field names, extra fields, headers, bearer token, digits-only numbers, JSON and GET; refused texts). Frontend `otp.test.js` (4).

### A2 — Change number ✅
- Settings → **Account → Change number**: current number (with "Verified by SMS"), new number + current password → **Send code** → enter the code sent to the **new** number → the number changes. Chats, groups, contacts and settings stay (same account). **Send again** (60 s countdown) and **Use a different number**. Without an SMS gateway the number changes right away after the password (not marked verified).
- The number can't be your current one or belong to another account in any format (`0092 333…` = `+92333…`); checked again when the code is entered. The Profile tab now shows the number read-only with **Change** (the profile form no longer changes it).
- Server: `PhoneChangeController`, `AccountService::changePhone`; `POST /settings/phone`, `POST /settings/phone/code`, `POST /settings/phone/resend`, `DELETE /settings/phone`.
- Tests: `ChangeNumberTest` (5: confirmed by SMS and chats stay, code used once; password / own number / taken in another format / invalid; send again, cancel, taken meanwhile; without SMS; profile form ignores the number). `ProfileTest` updated for the read-only number.

### A3 — Delete my account ✅
- Settings → **Account → Delete my account**: what will happen, current password, "I understand my account can't be recovered", a final confirm → signed out with "Your account has been deleted." Administrator accounts can't be deleted from settings.
- New `AccountDeletionService` (also used by the admin panel's delete): leaves every group (the longest member becomes admin if you were the last admin; an empty group ends), hands a community's admin role on before leaving (a community only you are in is deleted), deletes channels you are the only admin of (followed channels stay) and your broadcast lists, then removes one-to-one chats, all your messages (replies to them lose the quote, groups point at their newest remaining message), status updates, stickers, files and profile photo, blocks, notifications, sessions, reset tokens and SMS codes.
- Fixed on the way: the old admin delete removed **every conversation the user was in, including other people's whole groups and channels**. It now uses the same service, so groups of other people stay.
- Tests: `DeleteAccountTest` (2: password + tick needed, admins can't; full deletion with a photo chat, her group with a reply, someone else's group, own and followed channels, a shared and a solo community, broadcast list, status and sticker files, blocks — other people's data untouched). `AdminTest` still passes.

### A4 — Profile QR code ✅
- Chat ⋮ → **QR code**: your photo, name and QR code, **Scan code**, **Share link** (share sheet or copy) and **Reset QR code** (old code and link stop working). Also in Settings → Account → QR code with **Copy link** and **Reset**.
- **Scan code** uses the camera (same scanner as Linked devices, now shared in `lib/qr-scanner.js`); devices that can't scan paste the link. Scanning, or opening `/u/{token}` with the phone camera, shows "Chat with Bilal Cousin? @bilal · About" → **Message** opens the chat. Your own code says so. A code of someone who blocked you or is suspended looks like an old code. Guests sign in first and come back.
- Server: `ProfileQrController`, `AccountService::qrToken`; `GET /settings/qr`, `POST /settings/qr/reset`, `GET /u/{token}`, `POST /qr/lookup`. Frontend: `resources/js/chat/profile-qr.js`, `lib/qr-scanner.js`, settings QR drawn by `ui/settings.js` (QR library loaded only there).
- Tests: `ProfileQrAndExportTest` (QR made once, page and lookup without private details, own code, guests, reset, blocked / suspended owner, reset from settings). Frontend `account.test.js` (7: token from codes and links; my code, copy, reset; scan / paste → chat; opened from the camera, own and old codes; no routes = no menu item; shared camera scanning; settings QR).

### A5 — Download my account data ✅
- Settings → **Account → Download my account data**: **Download report** (a readable HTML page) or **Download as JSON**. Contains account details, settings and privacy choices, two-step status, saved contacts, blocked people, groups (role, members, community), communities, channels (owner / follower), broadcast lists, chat lists, activity counts (chats, messages sent / received, starred, calls, status updates, stickers, reports) and where you're signed in. **No messages or files**. Works in the Android app through its download manager. Limited to 10 per hour.
- Server: `AccountExportService`, `AccountController::export`, view `account/report.blade.php`; `GET /settings/export[?format=json]`; limiter `account-export`.
- Tests: in `ProfileQrAndExportTest` (JSON and HTML downloads with file names, right contents, no message text or password hash, limit).

### Checked in the browser (Phase 7)
Rendered pages: login with **Log in with phone number**, phone number page, code page (countdown running), Settings → Profile (read-only number, **Change** opens Account), Settings → Account (change number form, code step, errors, QR code drawn, downloads, delete) in light, dark and phone width, the account report; in the chat: the QR code panel, Scan code (paste mode) → "Chat with …?" → chat opened. No horizontal scrolling.

### Verification (Phase 7 complete)
PHPUnit **402 passed**, Vitest **170 passed**, Pint ✅, build ✅, migration rollback ✅. Deploy: `php artisan migrate` and `npm run build` (deploy script does both). For SMS login / verified number changes set `SMS_DRIVER` and the gateway keys in the server's `.env`, then `php artisan config:cache`. No new APK needed.

## WhatsApp-style Settings and Profile (after Phase 7) ✅
- The settings page is rebuilt like WhatsApp. **Phones and the Android app**: a **Settings** list (back arrow, your photo / name / About with a QR code button, then **Account**, **Privacy**, **Security**, **Chats**, **Notifications**, Admin panel for admins, **Log out**, "from One2One Chat"); tapping a row opens that section as its own screen with a back arrow. Back (the arrow, the browser or the Android back button) returns to the list; **Blocked contacts** returns to Privacy. **Web (768px+)**: the list on the left and the open section on the right, like WhatsApp Web.
- **Profile** screen like WhatsApp: big photo with a green camera button (Edit / Remove photo), then Name, About, Username and Email rows with a pencil (typed in place, **Save changes** appears after an edit), and the Phone row that opens Change number.
- Sections use flat WhatsApp rows and group titles instead of cards, underlined form fields, privacy / theme choices as rows that open the phone's own picker (the chosen value shows under the title), and switches for read receipts and notifications. **Chats → Theme** (System default / Light / Dark) replaces the old theme buttons. **QR code** is its own screen (card with photo and code, Share link, Scan code help, Reset). The old "Appearance & alerts" tab became **Chats** and **Notifications** (`?tab=preferences` still opens Chats).
- Chat ⋮ menu ordered like WhatsApp (New group, New community, New broadcast, Linked devices, Starred messages, QR code, Settings, Log out) and shown as plain text on phones; the rail's profile picture opens the Profile screen.
- Files: `resources/views/profile/edit.blade.php` (list + screens) and `resources/views/profile/sections/*.blade.php`, `resources/css/settings-wa.css`, `resources/js/ui/settings.js` (`initSettingsNav`, choice labels, theme choice, profile save bar, share link). The QR reset now returns to `?tab=qr`.
- Tests: new `resources/js/ui/__tests__/settings.test.js` (6: list → section and back with history, Blocked → Privacy, opened directly / Android back / browser back, wide screens, privacy choice label and rollback, theme choice, profile save bar). All existing settings tests pass unchanged except the QR reset redirect.
- Checked in the browser: list, Profile, Account, Privacy, Security, Chats, QR code on a phone (dark and light) and the two-pane web layout; chat ⋮ menu on a phone.
- Verification: PHPUnit **402 passed**, Vitest **176 passed**, Pint ✅, build ✅.

## Community members can't see each other (G10 privacy) ✅
- In a community's announcements, **members only see the community admins and themselves**; admins see everyone. Group info shows the real count ("248 members"), a **Community admins** list with "Community admin" badges and the note "Only community admins can see everyone in the community. Other members can't see you." The chat header shows only the member count (no names). No "Add members" for members.
- Nobody is told who joined, was added, left or was removed in the announcements (no notices; the leaving / removed person still keeps what they already received). Reactions and poll votes on announcements show members only their own; admins see who reacted / voted. No call buttons in the announcements.
- Groups inside a community stay normal groups: their members see each other (like WhatsApp).
- Existing "joined / added / left / removed" notices in announcement groups are deleted by the migration `2026_09_24_000001_remove_member_notices_from_community_announcements` (the chat list points at the newest remaining message).
- Server: `GroupService::payload` (`members_hidden`, filtered members), `addMembers` / `leaveGroup` / `markLeft`, `CommunityService::removeMember` / `joinWithLink`, `ConversationTypes::isAnnouncement` / `isAdmin` / `hidesMembersFrom`, `MessageResource` (reaction and voter ids). Client: `groups.js` (`memberCount`, group info, header summary), `calls.js` (no calls in announcements), `.group-info-private` style.
- Tests: `CommunityTest` +2 (members see only admins and themselves, admins see all, no join / add / leave / remove notices, reactions and votes hidden from members, quiet removal keeps visibility, community groups still show members; the migration removes old notices and keeps ordinary group notices). `groups.test.js` +1 (count-only header, private note and admins list, no Add members, admin view, no call buttons).
- Checked in the browser: community announcements info on a phone (dark).
- Verification: PHPUnit **404 passed**, Vitest **177 passed**, Pint ✅, build ✅.

## Admin panel (full control), bans and the feature check ✅
**Admin panel** (WhatsApp colours, menu slides in on phones):
- **Dashboard**: users, online, messages (today), chats, groups, channels, communities, live status updates, calls today, open reports, banned, blocked; messages per day for 14 days; account health (active / inactive / suspended / banned); newest users; latest admin activity.
- **Users**: search (parts of email / number allowed for admins), filters incl. **Banned**. A user's page: profile, ban details, email / mobile (verified), last seen, two-step, privacy, reports, where they're signed in, counts (sent / received / chats / groups / communities / channels / blocks), their chats (open any), and tools: **Ban** (1 / 3 / 7 / 30 / 90 days or permanent, with a reason the person sees), lift ban, **edit profile**, **make / remove administrator** (never yourself, never the last admin), activate / deactivate / suspend, **sign out of every device**, remove profile photo, turn off two-step verification, status updates, admin history, delete account. Other admins are protected until their role is removed.
- **Chats**: every one-to-one chat, group, community announcement, channel and broadcast list (search by name or person, per user). The **chat viewer** reads like WhatsApp (wallpaper, bubbles, sender names, photos / videos / voice / files / locations / contacts / polls / calls / replies / reactions / ticks, older pages) with **Delete for everyone** per message. **Search messages** across all chats with highlighted matches.
- **Groups, Channels, Communities**: lists and pages (members / followers / admins, details, groups of a community) with **delete** (groups show "An administrator deleted this group"; a deleted community's groups stay as ordinary groups). **Status updates**: live updates with previews and delete.
- **Audit log**: every chat opened, message search, ban / unban, status / role / profile edit, sign-out, photo removal, two-step reset, deletions, report review and settings change — who, when, network; filters. **App settings**: allow new accounts on / off, and a **notice for everyone** shown at the top of the chats (closable per browser).
- Honesty for users (chosen: notice + access log): Settings → Privacy → "Who can read chats" explains administrators can open chats and every access is recorded; the login page no longer says "Private by default".

**Ban screen**: banned people are signed out everywhere (sessions, remember token, phone push tokens) and see "Your account has been banned" with the reason, when it ends (or "Permanent") and since when; signing in shows it too; the app's requests get 403 `banned: true`. Temporary bans end by themselves (on the next visit and `chat:lift-bans` every 5 minutes).

**Feature check** (whole app reviewed; confirmed problems fixed):
- Delete my account did nothing (confirm dialog + loading spinner cancelled the submit).
- People added to a group later could quote older messages to read them, and got edits / reactions of older messages.
- Changing / resetting the password now signs out every other browser; changing the email needs the current password and the old address gets an email.
- User search no longer matches parts of emails or mobile numbers (only in full), so they can't be guessed letter by letter.
- A locked chat can't be taken out of "Locked chats" through the API without the code, and the notification bell hides locked chats.
- "My contacts" no longer includes strangers who only sent you a message (status, last seen, photo, About).
- Read receipts off now also hides blue ticks of broadcast list messages; channel followers don't see which admin posted; people who left a group no longer see its member list; photo privacy in the blocked list and incoming call notifications; forwarded media no longer carries @mentions; view once is only for one-to-one chats; two-step PIN guesses are also limited per account; abandoned group calls no longer keep someone "busy" (scheduled clean-up); "Add person" in a call works when the live connection missed the update; the status list reloads after closing the viewer; saving the profile returns to the Profile screen; app lock timeout select fixed on phones.

**Files**: `app/Http/Controllers/Admin/{UserModerationController,ChatController,SpaceController,SystemController}.php`, `AdminController`, `AdminContentService`, `AdminAuditService`, `BanService`, models `AdminAuditLog`, `AppSetting`, migration `2026_09_25_000001_create_admin_tools`, views `admin/**`, `auth/banned.blade.php`, `resources/css/admin.css` (rebuilt), middleware `EnsureAccountIsActive`, `EmailChangedNotification`.
**Tests**: `tests/Feature/Admin/{BanTest,AdminContentTest,AdminAccountsTest}`, `tests/Feature/Chat/SafetyFixesTest`, profile / privacy / search tests updated, `resources/js/ui/__tests__/forms.test.js`.
**Checked in the browser**: admin dashboard, chat viewer and user page (desktop and phone, dark), phone menu, ban screen.
**Verification**: PHPUnit **424 passed**, Vitest **179 passed**, Pint ✅, build ✅.
**Deploy**: `php artisan migrate` (deploy script). The scheduler must run for temporary bans to end on time (they also end on the person's next visit).

## Easier sign-up and login + .env settings in the admin panel ✅
**Sign-up** is now name, mobile number and password (no username, no "confirm password"):
- The username is made from the name (`awais.ahmed`, `awais.ahmed2`…) and can be changed in Settings.
- The email is optional by default (admin panel: Optional / Required / Don't ask); the column is now nullable. Without an email, the "Forgot PIN?" link can't work, so two-step verification asks for an email first; adding or changing an email needs the password.
- Mobile numbers can be typed as people know them: "0300 1234567" becomes "+923001234567" with the default country code (admin panel, default +92; other countries start with +). Login by number, phone login, change number and admin edits use the same rule, and old numbers saved without a code still log in.
- Passwords need 8+ characters with a letter and a number (no capital letter needed). "Remember me" is ticked by default.

**Admin panel → App settings** (saved in MySQL `app_settings`, no .env editing): sign-ups on/off, email at sign-up, default country code, notice for everyone, **SMS** (Twilio or any SMS web API, or log for testing), **Email / SMTP** (host, port, TLS/SSL, username, password, from), **Tenor GIF key**, **call relay (TURN)** and **invite link**. Passwords and API keys are encrypted with APP_KEY, never shown again ("Saved — leave empty to keep it", "Remove the saved value"); the audit log names changed settings, never values. A saved value wins over .env, an empty one falls back to .env; queued jobs pick up changes. **Send a test SMS / test email** buttons. Only the server basics (database, APP_KEY, APP_URL, Reverb) stay in .env.
- Files: `AppConfigService`, `AppSetting` (defaults, empty values stay empty), `Admin\SystemController` (settings, test SMS/mail), `admin/settings.blade.php` + `admin/partials/setting-field.blade.php`, `Phone::forAccount`, `AccountService::usernameFor`, `RegisterRequest`, `auth/register.blade.php`, migration `2026_09_26_000001_make_signup_simpler`.
- Tests: `tests/Feature/Auth/EasySignupTest` (5), `tests/Feature/Admin/AppSettingsTest` (3); phone login test updated.
- Checked in the browser: sign-up page (phone) and App settings (desktop, dark).
- Verification: PHPUnit **432 passed**, Vitest **179 passed**, Pint ✅, build ✅.

## New look: Indigo Night (no longer WhatsApp-style) ✅
Chosen style: **Indigo Night**, everywhere (chats, calls, status, groups, settings, sign in / sign up, admin panel), light and dark.
- **Colours**: indigo `#4F46E5` → violet `#7C3AED` gradients on lavender-tinted surfaces; night theme `#0B0C1D` / `#12132B`. New tokens in `resources/css/theme.css` (`--g-brand`, `--g-header`, `--g-bubble-out`, softer shadows, 12–24px radii).
- **Chats**: deep indigo side rail with a light indicator (web); pill search and filter chips (active chip in the gradient); rounded list rows with an accent for the open chat; gradient unread badges. On phones: gradient header with the app name, **floating pill bottom navigation** with the active tab in the gradient, gradient new-chat button.
- **Messages**: rounded 18px bubbles without tails; your own messages in the indigo → violet gradient (quotes, files, voice notes, polls, contact cards and buttons inside turn light automatically); pill date dividers and notices; a dotted chat background; a floating composer card with a gradient send button. The chat header is a gradient on phones.
- **Welcome screen**: new illustration and gradient title; the start-of-chat notes no longer claim chats are private (admins can open them).
- **Sign in / sign up**: gradient band with soft light and a floating card. **Settings**: gradient app bars on phones, indigo row icons. **Admin panel**: gradient brand strip, indigo accents, gradient bubbles in the chat viewer.
- Favicon, browser toolbar colour (`theme-color`), error pages and the account report use the new colours. In the Android app the status bar sits on the indigo gradient with light icons (the app icon was already indigo, so no new APK is needed).
- Files: `resources/css/skin.css` (replaces `whatsapp.css`), `settings-wa.css` renamed `settings-screens.css`, `theme.css`, `admin.css`, `native.css`, `resources/js/native/app.js`, `public/favicon.svg`, chat welcome art in `chat/index.blade.php`.
- Checked in the browser: chat list and chat with text, replies, polls, files, voice notes and contact cards (phone, light and dark), web layout with the rail and welcome screen, settings (phone), sign in (phone), admin dashboard.
- Verification: PHPUnit **432 passed**, Vitest **179 passed**, build ✅.

## Phase 8 — Media, storage and chat settings ✅
Decisions: **D8 = server backup file** (no Google Drive): a person downloads a ZIP of their chats from Settings, admins make a full server backup; moving to a new phone = sign in again (chats live on the server). **D4 = web and phone**, with a new APK.

### D1 — Media, links and docs ✅
- Chat menu → **Media, links and docs** (also in contact info and group info): tabs **Media / Docs / Links** with counts, newest first, loads older items while scrolling.
- Media: photos and videos by month ("This month", "August"…), video length and GIF badges; tap opens the photo viewer or video player, both now with **Show in chat**. Docs: file type colour, size, date, download. Links: every link of a message (code excluded), the link card picture and title when there is one.
- Only what I can see: messages deleted for me or for everyone, view once media and other chats never show up. Locked chats need the secret code (same rule as the chat).
- Server: `GET /conversations/{id}/gallery?kind=media|docs|links&before=` (`MessageService::gallery`, `LinkPreviewService::urls`). Client: `resources/js/chat/media-gallery.js`. The Android back button now closes info panels (`.group-info`) before leaving the chat.

### D2 — Chat wallpaper ✅
- **Settings → Chats → Wallpaper** (all chats) and **chat menu → Wallpaper** (one chat, only for me): Default, Plain, 7 colours with a light dot pattern, 5 gradients (Aurora, Sunset, Ocean, Forest, Midnight) — each has a light and a dark version — or **your own photo** with a live preview. Photos can be **dimmed in the dark theme**.
- Photos are re-encoded (max 1920 px JPEG, no EXIF/GPS), kept private (`chat` disk `wallpapers/{user}/`), served only to their owner, removed when replaced or when the account is deleted.
- Files: `WallpaperService`, `ChatPreferencesController`, `resources/js/ui/wallpaper.js` (picker), `resources/js/chat/chat-wallpaper.js`.

### D3 — Font size ✅
- **Settings → Chats → Font size**: Small / Medium / Large for message text and the typing box (phones keep 16 px or more in the typing box so they don't zoom). Changes on screen right away; stored per account (`data-font-size` on every page).

### D4 — Notification tone and vibration ✅ (new APK)
- **Settings → Notifications → Notification tone** (Default, Chime, Bell, Pop, Chirp, Marimba, Pulse, Glass; plays when chosen) and **Vibration** (Default, Short, Long, Off). **Chat menu → Notification tone** gives a chat its own sound (or None) and vibration.
- The server works out each person's sound per chat (chat choice → Settings; "notification sound" off = none) and sends it with the realtime notification, the phone's polling feed and the Firebase push (`tone`, `vibrate`).
- Web: tones are drawn with Web Audio from `resources/js/lib/tones.js`; phones' browsers vibrate. **Android**: the same notes are written to `res/raw/tone_*.wav` by `npm run tones`; each tone + vibration gets its own notification channel ("Messages: Bell tone, Short vibration"), made the first time it is needed; "Default" keeps the phone's own sound. Android 7 sets sound/vibration on the notification.
- **New debug APK**: `mobile/android/app/build/outputs/apk/debug/app-debug.apk` (built with Android Studio's JBR). Without the new APK, phones keep their usual sound.

### D5 — Media auto-download ✅
- **Settings → Storage and data → Media auto-download**: for **Wi-Fi** and for **mobile data**, tick Photos / GIFs and stickers / Video previews (default: all on Wi-Fi, photos and GIFs on mobile data).
- Anything not ticked shows a tile with the **file size and a download button**; videos show their size and still play on tap. The browser's Network Information (`connection.type`, Data Saver) decides the network; my own media is always shown. Voice messages and documents only ever download when opened.
- Files: `resources/js/chat/auto-download.js`, templates (held media), `ChatPreferences::autoDownload`.

### D6 — Manage storage ✅
- **Settings → Storage and data → Manage storage**: total space and files, a bar by kind (photos, videos, documents, voice, GIFs and stickers), **Larger than 5 MB**, and chats sorted by size.
- A chat (or the large files) opens a list sorted by **Largest / Newest** with thumbnails, select / select all and **Delete for me** (confirm shows the space). Other people keep their copy; a file nobody can see anymore is removed from the server.
- Loads only when the section is opened. Server: `StorageUsageService`, `StorageController` (`/settings/storage`, `/settings/storage/files`, `/settings/storage/delete`), `ChatCardService` (chat names as the person saved them). Client: `resources/js/ui/storage-manager.js`.

### D7 — Export chat ✅
- **Chat menu → Export chat**: **Without media** (.txt) or **Include media** (.zip with `chat.txt` and a `media/` folder). Lines look like `14/09/2026, 09:05 - Ayesha Baji: message`; deleted messages, view once media, locations (map link), contact cards, polls and calls are written as text; saved contact names are used.
- Only messages I can see; locked chats need the secret code; 6 exports a minute; media in one export capped by `CHAT_EXPORT_MAX_MEDIA_MB` (512). Needs PHP `zip` for media (the button says so otherwise).
- Files: `ChatExportService`, `ChatExportController`, `resources/js/chat/chat-export.js`.

### D8 — Chat backup ✅
- **Settings → Storage and data → Chat backup**: **Back up now** (optionally with photos and videos) makes a ZIP of **every chat** (`chats/<name>/chat.txt` + media, and a README) in the background; the row shows progress, then **Download** for `CHAT_BACKUP_KEEP_DAYS` (7) days. A new backup replaces the old one. The README and the settings text explain that a new phone gets everything back by signing in.
- **Admin panel → Backups**: **Back up now** (database, and optionally all uploaded files) → `database.sql` (made with PHP, no shell needed), `files/chat/`, `files/public/` and `RESTORE.txt`; list with status, size, download and delete; the newest `CHAT_SERVER_BACKUPS_KEEP` (5) are kept; making, downloading and deleting are in the audit log. The page has step-by-step restore instructions (same `APP_KEY`, import SQL, copy files, migrate).
- Backups run on the queue worker; when no worker runs, the scheduler (`chat:backups`, every minute) makes waiting backups, removes expired files, fails stuck ones and clears old export files.
- Files: migration `2026_09_27_000002_create_chat_backups_table`, `ChatBackup`, `BackupService`, `DatabaseDumpService`, `CreateBackup` job, `BackupController`, `Admin\BackupController`, `admin/backups/index.blade.php`, `resources/js/ui/backup.js`.

**Tests**: `tests/Feature/Chat/{MediaGalleryTest,ChatAppearanceTest,NotificationToneTest,ChatExportTest}`, `tests/Feature/Account/{StorageAndDataTest,ChatBackupTest}`; Vitest `media-gallery`, `wallpaper`, `tones`, `auto-download`, `storage-manager`, `backup`.
**Checked in the browser**: media gallery (phone and desktop, dark), wallpaper presets in the chat (light and dark) and the picker, font sizes, tone and export dialogs, held photos/videos/stickers, Storage and data (usage, large files with selection, auto-download, backup), admin Backups page.
**Verification**: PHPUnit **458 passed**, Vitest **203 passed**, Pint ✅, build ✅, debug APK ✅.
**Deploy**: `php artisan migrate` (new user / chat settings columns and `chat_backups`) and `npm run build` — the deploy script does both. The queue worker (or the scheduler cron) must run for backups. PHP `zip` extension is needed for ZIP exports and backups. Install the new APK for per-chat tones on Android.

## Admin panel upgrade: every account in detail, built for many more users ✅
- **User page with tabs**: Overview (16 numbers: messages sent/received, media and size, chats, groups made, communities, channels, calls and talk time, missed calls, contacts, blocks, status updates, phones, sign-ins; 30-day chart; last sign-in; wrong passwords warning), **Activity** (90-day chart, time of day, what they send with sizes, top chats), **Chats** (all, by kind, paged), **Groups & channels** (role, members, joined/left), **Devices & sign-ins** (browsers with "sign out this browser", phones with app version and push, trusted devices, sign-in history with method, device and network, other accounts on the same networks), **Contacts & blocks**, **Calls** (1:1 with length and result, group calls), **Reports** (about them and by them), **Settings & storage** (privacy, notifications, tone, wallpaper, auto-download, pinned/archived/muted/locked chats, storage by kind, last backup), **Admin history**. "Download all account data (JSON)".
- **Sign-in history**: new `user_logins` table filled from Laravel's auth events (password, SMS code, two-step, QR, new account, remembered device), wrong passwords for existing accounts and sign-outs; unknown accounts are never recorded; kept 180 days (daily clean-up).
- **Users list**: sort (newest, oldest, recently active, name), 15/50/100 per page, filters for joined, not seen for 30/90/180 days, Android app, two-step, role; **bulk actions** on selected accounts (sign out, suspend, activate, ban with length and reason, lift ban, delete) — administrators and your own account are skipped, every change is audited; **Export CSV** of the filtered list (streamed in chunks, spreadsheet-formula safe).
- **Scale**: new indexes (`messages.sender_id+created_at`, `messages.created_at`, `users.last_seen`, `calls.created_at`); dashboard numbers cached 1 minute and charts 5 minutes (Refresh button); with more than 50,000 accounts the user search matches the start of names/usernames/emails plus exact number or ID (index friendly), with a "search inside" option.
- **Dashboard**: 7/30/90-day range, charts for messages, people sending, new accounts and calls, most active people, what people send, Android vs browser use, storage (chat files, statuses, backups), sign-ins and wrong passwords today, and **Server health** (scheduler, queue waiting/failed, disk, database size, Firebase, Reverb, ZIP, PHP/Laravel).
- Files: `AdminInsightsService`, `Admin\UserDataController`, `RecordLoginActivity`, `UserLogin`, migration `2026_09_28_000001_add_admin_insights`, `admin/users/tabs/*`, `components/admin/bars.blade.php`, `resources/js/ui/admin-bulk.js`.
- Tests: `tests/Feature/Admin/AdminInsightsTest` (7). PHPUnit **465 passed**, Vitest **203 passed**, Pint ✅, build ✅. Checked in the browser: dashboard, users list with bulk bar, user overview, activity and devices tabs.

## Phase 9 — Apps and platform
### X6 — Desktop keyboard shortcuts ✅
Ctrl/⌘+K search chats · Alt+↑/↓ previous/next chat · Ctrl+Shift+F search in the chat · Ctrl+Alt+N new chat · Ctrl+Alt+Shift+N new group · Ctrl+Alt+E archive · Ctrl+Alt+Shift+M mute · Ctrl+Alt+Shift+P pin · Ctrl+Alt+Shift+U unread/read · Ctrl+Alt+, settings · Alt+1–5 Chats/Status/Channels/Communities/Calls · Esc closes menus and panels first, then the chat · Ctrl+/ (or menu → Keyboard shortcuts) lists them. Letters follow key positions, so other keyboard layouts work. Files: `resources/js/chat/shortcuts.js` (+ tests).

### X3 — Browser notifications and installable app (PWA) ✅
- **Notifications while the site is closed**: Settings → Notifications → "Notifications on this browser" (or the banner in the chats) subscribes the browser. New messages (with the chat's silent/vibration choice), incoming calls (stay until answered) and "read somewhere else" / "call ended" (removes the notification) arrive through standard **Web Push** — made on the server with PHP OpenSSL only: VAPID keys created once and kept encrypted in app settings, payloads encrypted with aes128gcm (RFC 8291). Several messages of one chat stay listed in one notification; clicking opens that chat (in the open tab if there is one). With a tab on screen the page shows messages itself; with push on, a hidden tab no longer shows its own pop-up or sound.
- Signing out on that browser, signing out other devices, changing or resetting the password, "sign out everywhere" and bans stop the browser's notifications; browsers that unsubscribed (404/410) are removed automatically. The same browser signing in with another account moves over.
- **Install**: web app manifest (name, colours, 192/512/maskable icons, shortcuts), Apple touch icon, "Install app" in the chats menu and in Settings → Notifications (when the browser offers it). Service worker (`public/sw.js`) also shows an **offline page** when there is no network; chats are never cached.
- Files: `WebPushService`, `WebPushController`, `WebPushSubscription` (migration `2026_09_28_000002`), `PushService::reachable()` (Firebase or browser), `public/sw.js`, `public/offline.html`, `public/icons/*`, `resources/js/lib/web-push.js`. Admin → Server health shows how many browsers signed up.
- Tests: `tests/Feature/Chat/WebPushTest` (decrypts the payload and verifies the VAPID signature like a browser), `resources/js/ui/__tests__/web-push.test.js`.
- Deploy note: the site must be on **HTTPS** (it is) for service workers and push.

### X4 — App update prompt ✅
- **Android**: Admin → App settings → **Android app**: latest version code and name, oldest allowed version code, what's new, and the **APK upload** (or a store link once on Google Play). The app compares its own version: older phones see **"Update available"** (Later is remembered per version), phones older than the oldest allowed see **"Update required"** that can't be closed. "Update" opens the download in the phone's browser (new `openExternal` native method; older apps fall back to the in-app download).
- **Public download link** `/download/android` (the uploaded APK with the right file type, or a redirect to the store) — share it to install the app.
- **Web**: every response carries `X-App-Version` (hash of the build manifest); a tab that was open during a deploy shows "A new version of One2One Chat is ready — Reload".
- Files: `AppUpdateService`, `Admin\AppReleaseController`, `AppShellController` (manifest + download), `resources/js/ui/app-update.js`, `NativeAppPlugin.openExternal`. Tests: `tests/Feature/Admin/AppReleaseTest`, `resources/js/ui/__tests__/app-update.test.js`.
