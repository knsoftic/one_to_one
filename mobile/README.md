# One2One Chat — Android app (Capacitor)

The Android app is a native shell around the live web app at **https://chat.hunario.com**.
Every chat feature comes from the server, so a normal `git pull` + deploy updates the app
for everyone — a new APK is only needed when the native part below changes.

What the app adds on top of the website:

| Feature | How |
|---------|-----|
| **Phone contacts like WhatsApp** | Reads names + numbers from the phone book (read-only permission, asked when the app opens) and matches them on the server with `POST /contacts/sync`. Refreshed quietly every 12 h. |
| **Push notifications like WhatsApp** | **Firebase Cloud Messaging** (free) delivers messages when the app is closed, in the background or open (except for the chat on screen). The app draws each notification: sender photo, the chat's recent messages in conversation style, **Reply** and **Mark as read** buttons, a group summary for several chats, Android 11+ conversation shortcut. The sender gets ✓✓ delivered as soon as the phone receives it; the notification disappears when the chat is read on another device. |
| **Fallback without Google Play services** | The app's own background service (Reverb WebSocket + polling, 15-minute safety check, restart after reboot) shows the same notifications. Used automatically when Firebase isn't available. |
| **Permissions on first open** | When the chat opens, the app explains and asks for Notifications, Contacts and Microphone — only those not granted yet (and not permanently blocked), at most once per launch — then asks once to run in the background. |
| **No / slow internet** | A bar at the top shows *No internet connection*, *Can't reach the server*, *Slow internet connection* and *Back online*. If the app can't load at all it shows an offline screen that retries by itself; a page still loading after 8 s shows a "Slow internet connection" screen with *Try again*. |
| **Downloads** | Documents and "Download" go to the system download manager (`Downloads/One2One Chat`). |
| **Voice notes** | Uses the microphone permission granted when the app opens (or asked the first time you record). |
| **Android back button** | Closes menus/sheets → leaves reply mode → closes the open chat → minimises the app. |
| **Status bar, splash screen, app icon** | Follow the app's light/dark theme; branded icon and splash. |

```
mobile/
├── capacitor.config.json      # app id, server URL, plugin settings
├── www/                       # offline page (+ redirect page)
├── resources/icon-512.png     # store icon
└── android/                   # native project (Android Studio)
    └── app/src/main/java/com/hunario/chat/
        ├── MainActivity.java              # splash, slow-internet screen, downloads, open chat from a notification
        ├── PushMessagingService.java      # Firebase push receiver
        ├── PushRegistrar.java             # Firebase token / fallback choice
        ├── ChatNotification.java          # one incoming message (push, WebSocket or feed)
        ├── AvatarLoader.java              # round sender photo or initials
        ├── NativeAppPlugin.java           # contacts, notification settings, battery exemption
        ├── ChatNotificationService.java   # fallback background connection (WebSocket + polling)
        ├── MessageNotifier.java           # WhatsApp-style notifications, Reply / Mark as read, shortcuts
        ├── NotificationSettings.java      # stored connection details
        ├── NotificationFeed.java          # polling feed shared by the service and the safety check
        ├── KeepAliveReceiver.java         # 15-minute safety check if the service was killed
        ├── BootReceiver.java              # reconnect after reboot / app update
        └── NotificationActionReceiver.java # Reply / Mark as read buttons
```

The web side lives in the Laravel project: `resources/js/native/` (loaded only inside the app),
`resources/css/native.css`, `DeviceController`, `DeviceApiController`, `DeviceService`, `PushService`, `SendMessagePush`, `SendReadPush`, `routes/api.php`.

---

## 1. Requirements (Windows, macOS or Linux)

- **Node.js 20+**
- **JDK 21 or newer** — the JDK bundled with Android Studio works
  (`C:\Program Files\Android\Android Studio\jbr`)
- **Android SDK** with *Android 16 (API 36)* platform and build-tools (install from Android Studio → SDK Manager)

Set the environment for the current terminal (PowerShell example):

```powershell
$env:JAVA_HOME = "C:\Program Files\Android\Android Studio\jbr"
$env:ANDROID_HOME = "C:\Android\sdk"        # or %LOCALAPPDATA%\Android\Sdk
```

## 2. Build a test APK

```bash
cd mobile
npm ci
npx cap sync android
cd android
./gradlew assembleDebug          # Windows: gradlew.bat assembleDebug
```

Output: `mobile/android/app/build/outputs/apk/debug/app-debug.apk`

**Install on a phone**

- Copy the APK to the phone and open it (allow *Install unknown apps* for your file manager / browser), or
- With USB debugging enabled: `adb install -r app/build/outputs/apk/debug/app-debug.apk`

Debug builds are for testing. Use a signed release build (section 4) for real users and the Play Store.

## 3. Push notifications

### 3.1 How it works

After sign-in the app asks for the permissions it doesn't have yet (notifications, contacts, microphone) and gets its
own device token from `POST /devices`. Then it picks the delivery method:

| | Firebase push (normal case) | Fallback background connection |
|---|---|---|
| When | This APK contains `google-services.json`, the phone has Google Play services and the server has `FCM_CREDENTIALS` | Anything of that is missing |
| App closed | ✅ Google delivers the message and wakes the app | ✅ Foreground service stays connected |
| Extra notification | None | Quiet "Background connection" notification |
| Battery permission | Not needed | Asked once |
| Server needs | Service-account JSON | Working Reverb (or polling every 60 s) |

Either way the notification looks the same: sender photo, recent messages of the chat, **Reply** / **Mark as read**,
✓✓ delivered for the sender, removed when the chat is read elsewhere, nothing for the chat currently on screen.

A **force-stopped** app (Settings → Apps → Force stop) receives nothing until it is opened again — an Android rule
for every app, WhatsApp included.

### 3.2 Set up Firebase (once)

Firebase Cloud Messaging is free.

1. <https://console.firebase.google.com> → **Add project** (Google Analytics not needed).
2. **Add app → Android**, package name **`com.hunario.chat`** → *Register app*.
3. Download **`google-services.json`** and put it at `mobile/android/app/google-services.json` (git-ignored).
4. Rebuild the APK (section 2 or 4). The build log no longer says "Firebase push is disabled".
5. ⚙ **Project settings → Service accounts → Generate new private key**, then on the server (aaPanel guide step 18):

   ```bash
   # upload to storage/app/private/firebase-service-account.json, then:
   chown www:www storage/app/private/firebase-service-account.json
   chmod 640 storage/app/private/firebase-service-account.json
   ```

   `.env`: `FCM_CREDENTIALS=storage/app/private/firebase-service-account.json`, then `php artisan config:cache`.

6. Open the app and sign in: it registers for push automatically (`device_tokens.fcm_token`).

**Phone brands with aggressive battery savers** (Xiaomi/Redmi, Oppo, Vivo, Realme, Huawei): Firebase push usually
works without changes; if messages arrive late, allow *Autostart* and set battery usage to *No restrictions*.

## 4. Release APK / Play Store bundle

Create an upload key **once** and keep it safe (you need the same key for every update):

```bash
keytool -genkeypair -v -keystore mobile/android/one2one-release.jks -alias one2one -keyalg RSA -keysize 2048 -validity 10000
```

Create `mobile/android/keystore.properties` (git-ignored):

```properties
storeFile=one2one-release.jks
storePassword=your-store-password
keyAlias=one2one
keyPassword=your-key-password
```

Build:

```bash
cd mobile/android
./gradlew assembleRelease     # APK  → app/build/outputs/apk/release/app-release.apk
./gradlew bundleRelease       # AAB  → app/build/outputs/bundle/release/app-release.aab (Play Store)
```

Before each release, raise `versionCode` (and `versionName`) in `mobile/android/app/build.gradle`.

> **Play Store note:** the fallback background connection uses a *special use* foreground service and asks for the battery
> optimisation exemption. Google Play reviews both; in the Play Console declare them as "real-time chat
> notifications on phones without Google Play services". Sideloaded APKs need nothing extra.

## 5. Common changes

| Change | Where |
|--------|-------|
| Server address | `server.url` in `capacitor.config.json` and `APP_URL` / `PING_URL` in `www/offline.html`, then `npx cap sync android` |
| App name | `appName` in `capacitor.config.json` and `android/app/src/main/res/values/strings.xml` |
| Icon / splash | `android/app/src/main/res/drawable/ic_launcher_*.xml`, `splash_icon.xml`, `mipmap-*/ic_launcher*.png` |
| Notification texts | `android/app/src/main/res/values/strings.xml` |
| Permissions | `android/app/src/main/AndroidManifest.xml` |

After changing `capacitor.config.json`, `www/` or plugins, always run `npx cap sync android` before building.

## 6. Troubleshooting

| Problem | Fix |
|---------|-----|
| `Unsupported class file major version` / Gradle fails to start | Use JDK 21+ (`JAVA_HOME`), e.g. Android Studio's `jbr` folder. |
| `SDK location not found` | Set `ANDROID_HOME`, or create `android/local.properties` with `sdk.dir=C\:\\Android\\sdk`. |
| App shows "You're offline" | The phone can't reach `https://chat.hunario.com` (network, DNS or an SSL problem on the server). |
| No notifications at all | Open the app once and sign in; allow notifications; the account's *Notifications* setting must be on; the latest server code must be deployed. For Firebase push also check section 3.2 (APK built with `google-services.json`, `FCM_CREDENTIALS` on the server). |
| Notifications arrive late | The WebSocket isn't connecting (Reverb/Nginx on the server) so the app is polling; and/or Android is restricting the app — allow *Unrestricted* battery usage and *Autostart*. |
| Notifications stop after some hours | Battery saver killed the app: see the phone-brand note in section 3. Opening the app reconnects it. |
| Contacts never match | Numbers are matched on the last 9 digits; the other person must have registered with the same mobile number. Allow Contacts permission in Android settings → Apps → One2One Chat. |
| New web changes not visible | Web changes need a server deploy (`scripts/deploy.sh`), not a new APK. Swipe the app away and reopen it. |

## iOS

The same Capacitor project can produce an iOS app on a Mac with Xcode (`npm i @capacitor/ios && npx cap add ios`),
but iOS does not allow apps to keep their own background connection: notifications on iPhone would require
Apple Push Notification service (APNs). The contacts reader in `NativeAppPlugin` is Android-only.
