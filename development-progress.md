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
