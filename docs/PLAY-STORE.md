# One2One Chat — Google Play release guide

Everything needed to publish the Android app on Google Play. The app project is ready
(package `com.hunario.chat`, target SDK 36, release signing from `keystore.properties`);
the steps marked **You** need your own accounts and can't be done from the code.

> Live copy with contents and copy buttons: **Admin panel → Guides → Google Play release guide** (<https://chat.hunario.com/admin/docs/play-store>, admins only).

---

## 1. Before you start (You)

| What | Where | Cost |
|------|-------|------|
| Google Play developer account | <https://play.google.com/console/signup> — personal or organisation; identity check takes a few days | $25 once |
| A test device or emulator with Android 8+ | — | — |
| Firebase project (for push notifications) | <https://console.firebase.google.com> → add Android app `com.hunario.chat` → download `google-services.json` into `mobile/android/app/` (git-ignored) | free |
| Contact email for the store listing and legal pages | Admin panel → App settings → **Legal pages** | — |

New **personal** developer accounts must run a **closed test with at least 12 testers for 14 days** before the app can go to production. Organisation accounts skip this.

---

## 2. Create the upload key (You, once)

Keep this file and its passwords safe forever — without them you can't update the app
(Play App Signing lets Google reset the *upload* key, but it takes days).

```bash
keytool -genkeypair -v -keystore one2one-upload.jks -alias one2one -keyalg RSA -keysize 2048 -validity 10000
```

Put the `.jks` file in `mobile/android/` (git-ignored: `*.jks`) and create
`mobile/android/keystore.properties` (git-ignored):

```properties
storeFile=one2one-upload.jks
storePassword=YOUR_STORE_PASSWORD
keyAlias=one2one
keyPassword=YOUR_KEY_PASSWORD
```

Never commit these two files or send them to anyone.

---

## 3. Build the release bundle

1. Raise the version in `mobile/android/app/build.gradle` for every upload:
   `versionCode` (whole number, +1 each time) and `versionName` (what people see, e.g. `1.2`).
2. Build (Windows PowerShell, from `mobile/android`):

```powershell
$env:JAVA_HOME = 'C:\Program Files\Android\Android Studio\jbr'
.\gradlew.bat bundleRelease
```

The file to upload is `mobile/android/app/build/outputs/bundle/release/app-release.aab`.
For a sideloaded APK instead: `.\gradlew.bat assembleRelease` → `app/build/outputs/apk/release/app-release.apk`
(upload it in Admin → App settings → Android app to show "Update available" to phones outside Play).

---

## 4. Create the app in Play Console

**All apps → Create app**: name *One2One Chat*, default language English, App, Free, accept the declarations.

### Store listing

**Short description (≤ 80 characters)**

> Chat, call and share photos with the people you know — fast, simple, private.

**Full description**

> One2One Chat keeps you close to family, friends and colleagues.
>
> • Messages that arrive instantly, with read receipts, replies, reactions, formatting and message search
> • Voice and video calls, including group calls and call links
> • Photos, videos, voice messages, documents, stickers, GIFs, polls, locations and contact cards
> • Groups, communities, channels and broadcast lists
> • Status updates that disappear after 24 hours
> • Disappearing messages, view once media, chat lock with a secret code and app lock with your fingerprint
> • Two-step verification, blocking and reporting, and privacy controls for last seen, profile photo and About
> • Your own wallpaper, notification tone and text size for each chat, dark mode
> • Share photos and files from any app, export chats, download a backup, manage storage
> • Works on your computer too at chat.hunario.com, with notifications when the site is closed
>
> Sign up with your mobile number. Your chats are kept on our server, so a new phone gets them back as soon as you sign in.

**Graphics**

| Asset | Size | Source |
|-------|------|--------|
| App icon | 512 × 512 PNG | `public/icons/icon-512.png` (or `mobile/resources/icon-512.png`) |
| Feature graphic | 1024 × 500 PNG/JPG | make one with the indigo → violet gradient `#4f46e5 → #7c3aed`, the icon and the tagline |
| Phone screenshots | 2–8, 1080 × 1920 (or 9:16) | chats list, a chat with photos, a call, status, settings, dark mode |

**Category**: Communication · **Contact email**: the legal email · **Website**: `https://chat.hunario.com`
**Privacy policy**: `https://chat.hunario.com/privacy`

### App content (Policy → App content)

| Section | Answer |
|---------|--------|
| Privacy policy | `https://chat.hunario.com/privacy` |
| App access | **All or some functionality is restricted** → give a test account: a mobile number + password you created for reviewers (turn off two-step verification on it) |
| Ads | No ads |
| Content rating | Questionnaire: *Communication / social*; users can talk to each other: **Yes**; share location: **Yes (optional)**; digital purchases: No → usually rated *Teen / 12+* |
| Target audience | 13 and older (not designed for children) |
| News app | No |
| Data safety | See section 5 |
| Government app | No |
| Financial features | None |
| Health | None |
| **Child safety standards** (required for social apps) | Standards URL `https://chat.hunario.com/child-safety`; in-app reporting: **Yes** (Report user); child safety contact: the legal email; complies with CSAM laws: **Yes** |
| **Account deletion** | In-app deletion: **Yes** (Settings → Account → Delete my account); web link: `https://chat.hunario.com/delete-account` |
| Foreground service permissions | `specialUse` (background connection without Google Play services) and phone call (ongoing call): describe as in the manifest — *"Keeps a connection to the app's own chat server to deliver new message notifications on phones without Google Play services"* and *"Keeps an ongoing voice/video call running while the screen is off"*; add a short screen recording if asked |
| Full-screen intent | Used for **incoming calls** only |

---

## 5. Data safety form

Data is **encrypted in transit** (HTTPS): **Yes**. People can **request deletion**: **Yes** (`/delete-account`).
No data is sold or used for ads. "Shared" below means sent to a service provider acting for us (allowed without declaring as sharing).

| Data type | Collected | Optional? | Purpose |
|-----------|-----------|-----------|---------|
| Name | Yes | Required | App functionality, account management |
| Email address | Yes | Optional | Account management (password reset, two-step) |
| Phone number | Yes | Required | Account management, app functionality |
| User IDs | Yes | Required | App functionality |
| Photos and videos | Yes | Optional | App functionality (messages, profile photo, status) |
| Audio (voice messages) | Yes | Optional | App functionality |
| Files and docs | Yes | Optional | App functionality |
| Messages (in-app messages) | Yes | Required | App functionality |
| Contacts | Yes | Optional | App functionality (find friends who use the app) |
| Approximate / precise location | Yes | Optional | App functionality (share location in a chat) |
| App interactions / diagnostics | Sign-in history, IP address, device model | Required | Security, fraud prevention |
| Device or other IDs | Push token | Required | App functionality (notifications) |

Because administrators can read chats for moderation, don't tick "end-to-end encrypted".

---

## 6. Release

1. **Testing → Closed testing** (or Internal testing first): create a track, add testers' emails, upload the `.aab`, write release notes (same text as "What's new" in the admin panel).
2. After the test period: **Production → Create release** → upload → roll out (start with 20 % if you like).
3. After approval, put the Play link in the admin panel: App settings → **Invite link** and Android app → **Store link**, so invites and "Update available" open Google Play.

## 7. Every update

1. `versionCode` +1 and new `versionName` in `build.gradle`.
   If the app name or icon changed in **Admin → App settings → App name & icon**, first copy them into the app:
   `php artisan app:android-brand --url=https://chat.hunario.com` (phones show the new name and icon after this update).
2. `.\gradlew.bat bundleRelease` → upload to Play.
3. Admin → App settings → Android app: set the same version code/name and "What's new" (add an *oldest allowed version* only when old apps stop working).
4. Web-only changes need no new app: deploy the site as usual.

## Checklist

- [ ] Developer account verified
- [ ] `google-services.json` in place, push works on a release build
- [ ] Upload key + `keystore.properties` created and backed up
- [ ] Legal pages filled in (Admin → App settings → Legal pages) and opened once: `/privacy`, `/terms`, `/child-safety`, `/delete-account`
- [ ] Reviewer test account made
- [ ] Store listing text, icon, feature graphic, screenshots
- [ ] Content rating, target audience, data safety, child safety, account deletion, foreground service and full-screen intent declarations
- [ ] Closed test (12 testers / 14 days for new personal accounts)
- [ ] Production release, then Store link and Invite link in the admin panel
