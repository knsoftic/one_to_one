# Google Play Billing — set-up and testing

Coins, plans and the verified badge are digital goods, so inside the Android app they can only be
sold through **Google Play Billing** (Play policy). The web app keeps its own methods (manual
JazzCash / EasyPaisa / bank, Stripe, PayPal); the app never shows them and the server refuses them
for the app's user agent (`One2OneApp/`).

How it works, in one line: the app opens the Play purchase sheet → Play hands the app a
**purchase token** → the app posts it to `POST /pay/play/verify` → the server checks the token with
the Play Developer API and credits the coins / activates the plan → only then does the app
**consume** the token (which is what lets the user buy the same item again). See
`docs/MONETISATION-DESIGN.md` sections B.8 (`PlayGateway`), E.3, E.10 and H.

Pieces:

| Where | What |
|---|---|
| `mobile/android/app/src/main/java/com/hunario/chat/BillingPlugin.java` | Capacitor plugin `One2OneBilling`: `isAvailable`, `getProducts`, `purchase`, `getPendingPurchases`, `consume`, event `purchaseUpdated` |
| `mobile/android/app/build.gradle` | `com.android.billingclient:billing:7.1.1` |
| `resources/js/native/billing.js` | `playAvailable()`, `playProducts()`, `buyOnPlay()`, `recoverPurchases()`, `onPurchaseUpdated()` |
| `resources/js/native/app.js` | on every app open: `recoverPurchases()` (delivers purchases that were paid but never confirmed) and re-runs it when a pending purchase completes |
| Admin → Settings → Paid features → Google Play | `play_enabled`, `play_package_name`, `play_service_account` (JSON), `play_min_app_code` |
| Admin → Plans / Coin packs | the **Play product id** of each item (`play_product_id`) |

Plans are sold as **consumable one-time products** ("1 month Pro", "1 year Pro"), not as Play
subscriptions: buy → runs → expires → buy again, the same model as on the web.

---

## 1. Play Console — one-time set-up

You need a Google Play Developer account and the app (`com.hunario.chat`) created in the Play
Console.

### 1.1 Upload the app to a testing track

Purchases (even test purchases) only work for an app that Google knows. Build a **signed release
bundle** (mobile/README.md section 4) that contains the billing plugin — the manifest must carry
the `com.android.vending.BILLING` permission, which the billing library adds by itself — and upload
it to the **Internal testing** track (Release → Testing → Internal testing → Create release).
The bundle does not have to be published to production; it must simply exist on some track with the
same package name and signing key as the build you test with.

### 1.2 Create the in-app products

Play Console → Monetise → Products → **In-app products** → Create product. For every coin pack and
every plan you want to sell in the app:

| Field | Value |
|---|---|
| Product ID | exactly the id you will type into Admin → Coin packs / Plans → *Play product id*, e.g. `coins_500`, `plan_pro_month`, `plan_pro_year`. Lowercase letters, digits, `_` and `.`; it can never be changed or reused once created |
| Name / Description | what the Play sheet shows (e.g. "500 coins", "Pro — 1 month") |
| Price | the Play price per country (Play takes its fee — set the price accordingly). The app shows **this** price, never the admin's PKR price |
| Status | **Active** |

Then in the admin panel put the same id in the item's *Play product id* field. An item without a
Play id is simply not offered inside the app. An **id Play does not know** comes back missing from
the price lookup: a coin pack with such an id is left out of the Wallet list until the id is fixed,
a plan card still shows (with "Price on Google Play" and no price) and fails at the moment of
purchase with "This product is not available on Google Play". Either way the id is wrong — *Test
connection* (1.3) names every id the Play Console does not have.

Do **not** create them as subscriptions — the verification uses `purchases.products`, which only
understands one-time products.

### 1.3 Service account (server-side verification)

The server verifies each purchase token with the **Google Play Developer API**, which needs a
service account:

1. Google Cloud Console → the project linked to the Play Console (Play Console → Setup → API
   access shows / creates it) → IAM & Admin → **Service accounts** → Create. Name it e.g.
   `play-verify`. No project roles are needed.
2. Keys → Add key → **JSON** → download. This file is a secret.
3. Play Console → Users and permissions → **Invite new users** → the service account's e-mail →
   App permissions: this app only → Account permissions: **View financial data, orders and
   cancellation survey responses** and **Manage orders and subscriptions** (these two are what
   `purchases.products.get`, `purchases.products.acknowledge` and `purchases.voidedpurchases`
   need). Nothing else.
4. Admin panel → Settings → Paid features → Google Play: paste the JSON into *Service account*,
   set *Package name* to `com.hunario.chat`, switch *Google Play* on, then press **Test connection**
   (it lists the in-app products through `inappproducts.list`; a 401/403 means the invitation or
   the permissions in step 3 are missing — a new service account can take up to 24 h to be
   accepted by the Play API).

The JSON is stored encrypted and never shown again; paste a new one to rotate it.

### 1.4 Licence testers (free test purchases)

Play Console → Setup → **Licence testing** → add the Google accounts of the people who will test.
Purchases made by these accounts on a testing-track build are **not charged** (Play shows "Test
card, always approves" and other test instruments, including "slow test card" and "test card,
declines"). The same accounts must also be added as testers of the internal testing track and must
**opt in** through the track's link.

Voided / refunded test purchases show up in `purchases.voidedpurchases` exactly like real ones, so
the daily `chat:play-sweep` can be tested too.

### 1.5 Minimum app version (optional)

`play_min_app_code` (Admin → Settings → Google Play) is the lowest `versionCode` of the app that
contains the billing plugin. Older installs see "Update the app to buy" instead of a broken Play
sheet. The first build with billing bumps `versionCode` in `mobile/android/app/build.gradle`; put
that number here.

---

## 2. Building the app with billing

```powershell
cd mobile
npm install
npx cap sync android
cd android
.\gradlew.bat :app:compileDebugJavaWithJavac   # quick compile check
.\gradlew.bat assembleDebug                    # debug APK (billing needs a licence-tester account)
.\gradlew.bat bundleRelease                    # signed bundle for the Play testing track
```

Requirements as in mobile/README.md: JDK 21+ (`JAVA_HOME`, e.g. Android Studio's `jbr` folder —
JDK 17 fails with `invalid source release: 21`), `ANDROID_HOME`, and network access the first time
so Gradle can download `com.android.billingclient:billing:7.1.1`.

A **debug** build can make test purchases as long as: the same package name is on a testing track,
the Google account on the phone is a licence tester, and the app was installed from Play at least
once *or* the debug build is signed with the upload key. In practice the easiest path is: upload
the release bundle to internal testing, install it from the testing link, test there.

---

## 3. Testing the flow

Before starting: Admin → Settings → Paid features → *Paid features* **on**, *Google Play* **on**,
package name + service account saved, Test connection green; at least one coin pack and one plan
with a Play product id; the phone signed into a licence-tester Google account; the app opened from
the testing track.

| Case | Steps | Expected |
|---|---|---|
| Normal purchase | Wallet → pick a pack → Play sheet → "Test card, always approves" → Buy | Wallet balance rises within a second; Admin → Payments shows a `fulfilled` payment with gateway `play`, order id `GPA.…`; the ledger has one `payment:{id}` row; the item can be bought again |
| Plan | Premium → plan → Buy | Subscription `active` with the snapshot; badge / ads-off apply on the next screen; monthly coins credited once |
| Cancel | Open the sheet, press back | "Purchase cancelled." — no request to the server, nothing in Payments |
| Slow / pending payment | Choose "Slow test card, approves after a few minutes" | The app says "Waiting for Google Play…"; Payments shows `pending`; a few minutes later the plugin's `purchaseUpdated` event (or the next app open) re-verifies → `fulfilled`, coins credited, no second row |
| Declined | "Test card, declines" | Play shows the failure; nothing on the server |
| Network drop before verify | Buy, and switch aeroplane mode on the moment the Play sheet closes | The app says "Could not confirm your purchase… try again"; the token is **not** consumed. Turn the network back on, kill and reopen the app → `recoverPurchases()` re-posts the token → credited → consumed |
| Server can't reach Google (503) | Temporarily paste a wrong service-account JSON, buy | "Google Play could not be reached. Try again"; not consumed; fix the JSON, reopen the app → delivered |
| Replay | Reopen the app several times after a purchase | No extra credits (the server answers 200 for the same token; the ledger key is unique) |
| Another account's token | Sign in as user A, buy; sign out, sign in as user B on the same phone, reopen | B gets nothing; the server answers 409 `token_other_account` and logs it; the token stays unconsumed until A signs in again |
| Refund / void | Play Console → Order management → refund the test order **(tick "revoke")**, or wait for the test purchase to be voided | Next `php artisan chat:play-sweep` → payment `refunded`, coins clawed back (shortfall recorded when already spent), the user is notified "Google refunded this purchase" |
| Web is unaffected | Open the site in a browser | No Play method offered; `POST /pay/play/verify` from a browser UA answers 422 |

Useful while testing:

- `adb logcat -s One2OneBilling Capacitor/Plugin BillingClient` — plugin and library logs.
- `php artisan chat:play-sweep` — run the voided-purchases check by hand.
- Admin → Money → Overview lists amount mismatches, shortfalls, `paid`-but-not-`fulfilled`
  payments and Play 409s from the last 7 days.
- `npx vitest run resources/js/native/__tests__/billing.test.js` covers the JS order of
  operations (purchase → verify → consume; no consume on 503 / network / 202; recovery; cancel).

## 4. Things that bite

- **Unconsumed purchases are refunded by Google after 3 days.** The app only consumes after the
  server said 200; if the server keeps failing (bad service account, wrong package name) the user
  gets their money back automatically and no coins — check *Test connection* first.
- **Product ids are permanent.** A deleted Play product id cannot be recreated; use a new id and
  update the admin field.
- **A refunded purchase Play still holds.** Once the sweep has recorded the refund, the app's next
  verify of that token answers 422 `voided`, `cancelled` or `consumed` — answers that will never
  change — and the app consumes the token on those three. Without that the purchase would sit in
  Play's queue forever and Play would keep refusing to sell the item again
  (`ITEM_ALREADY_OWNED`). Every other failure (503, network, 202, 409) still leaves the token
  untouched.
- **Prices**: the app shows Play's localised `ProductDetails` price; never mention web prices inside
  the app (anti-steering rule).
- **New service account**: the Play API may answer 401 for up to 24 hours after inviting it.
- **Same package, different signing key** (a debug build not signed with the upload key) ⇒ Play
  answers `BILLING_UNAVAILABLE` / "the item you requested is not available for purchase".
