# Monetisation layer — final design

Repo `C:\xampp\htdocs\one to one` (Laravel 12 / vanilla ES-module JS / Capacitor Android). This is the merged, corrected design: the **product** design's UX and codebase fit, with the **integrity** design's ledger/payment guarantees and the **policy** design's platform rules grafted in, and every judge flaw resolved. Names below match the code as it exists today (`AdCampaign`, `AdService::countView()`, `AdPlacement`, `AppSetting::DEFAULTS`, `AppConfigService::FIELDS`, `AdminAuditService::record()`, `SystemController::updateSettings()` with its `ads_section` marker, `profile/edit.blade.php` `$sections`, `AppConfigComposer` / `ChatConfigComposer`, `chat:*` closure commands in `routes/console.php`, `NativeAppPlugin` + `resources/js/native/plugins.js`, the `One2OneApp/1.0` user agent from `mobile/capacitor.config.json`).

## 0. Decisions that resolve the contradictions between the three drafts

| Topic | Decision |
|---|---|
| Master switch | One: `paid_enabled` (default **false**). Feature toggles `promote_enabled`, `referral_enabled` and `badge_coin_price > 0` only matter while the master is on. Off ⇒ Premium/Wallet/Promote/Refer rows hidden, every purchase/promote/refer route 404 (middleware `paid`), balances/subscriptions/ledger/admin pages/expiry keep working. |
| Wallet | Own `wallets` table (user_id PK, **unsigned** balances), never the hot `users` row. |
| Ledger keys | `coin_transactions.idempotency_key` **NOT NULL unique** on every row, derived from the cause; checked inside the wallet row lock. |
| Promotion create order | Insert the `ad_campaigns` row **first** (inside the transaction), then debit with key `promo:{campaign_id}:hold`; a debit failure rolls the row back. `client_token` (unique) on the campaign makes a retried submit return the same row. |
| Payment states | `pending → review → paid → fulfilled` plus `failed`, `rejected`, `cancelled`, `refunded`. `paid` and `fulfilled` are **separate** (money taken but not delivered is visible; admin **Retry**). |
| Webhook idempotency | `payment_events` unique `(gateway, event_id)` **with `processed_at`**: duplicate with `processed_at` set ⇒ 200 no-op; row with `processed_at` NULL (previous attempt threw ⇒ 500) ⇒ re-process. "Row exists" never means "done". |
| Stripe | Hosted **Checkout Session** (no Stripe.js, CSP-safe). Fulfil only from the signature-verified webhook or a server-side `Session::retrieve`. |
| PayPal | Redirect approval flow (no PayPal JS, CSP-safe); **server-side capture** on return with `PayPal-Request-Id = payment uuid`; `price_usd_minor` per plan/pack; PayPal hidden/rejected when null. |
| Platform gating | Server decides from the User-Agent marker `One2OneApp/` (best-effort client signal; Play verification is the real guard). App ⇒ only `play`; web ⇒ `manual/stripe/paypal`. Wrong gateway for the platform ⇒ **422**, not just hidden. No `X-App-Platform` header. |
| Plans on Play | **Consumable INAPP products** ("1 month Pro", "1 year Pro"): same buy-run-expire-renew model as web, no proration, no subscriptionsv2/RTDN. Voided-purchases sweep covers refunds. Upgrade path to Play subscriptions is noted in J. |
| Play account binding | `obfuscatedExternalAccountId = sha256(user id . APP_KEY)` — stable per user so restore can bind tokens. |
| Renewal / plan change | Same plan while active ⇒ extend `ends_at`. Different plan while active ⇒ new row `queued` starting at the old `ends_at` (no proration, no lost days). Renewal after expiry ⇒ new period from now. |
| Benefits | Snapshot in `subscriptions.benefits`; every entitlement check reads the snapshot. Plan edits never change a running sub. |
| Monthly coins | Keyed by **period index** from `starts_at` (`plan-coins:{sub}:{n}`), first grant at activation (n=0). |
| Badge | `users.verified_until` for coin/admin badges; the plan badge is **computed** from the active sub's snapshot (no expiry sync bug). Buying while a plan badge is active is allowed and extends from `max(now, verified_until)`. Idempotent by client token. |
| Refund rounding | Stop/target-vanished refund = `coins_spent − ceil(impressions × rate / 1000)`, clamped to `[0, coins_spent]`, once (`refunded_at`). Reject = full. Finished = none. |
| Withdrawable share | Every ledger row stores `withdrawable_delta`; refunds restore the withdrawable share proportionally to the original debit. |
| Manual txn id | Not unique; duplicates **flagged** to the admin. `gateway_ref` for manual = `manual:{payment id}` (set at approval). |
| System actions | Not audit rows (`AdminAuditService::record()` requires a `User`; no schema change). Expiry, sweeps and clawbacks are visible through the data itself (`subscriptions.status`, `payments.meta.reason`, ledger rows) and `Log::info`. |
| Idioms | Flat `app/Services/` (+ `app/Services/Payments/` for gateway drivers), `chat:*` commands, existing limiters (`chat-lock` on money POSTs), `paid_section` marker on the existing settings form, settings-page JS in `resources/js/ui/` (lazy-imported from `initSettings()` like `storage-manager.js`), chat-page JS in `resources/js/chat/`, native in `resources/js/native/`. |
| `target_url` | Stays NOT NULL. Internal kinds store a real page URL: channel ⇒ `route('channels.link', token)`, community ⇒ `route('communities.join.show', token)`, status/business ⇒ `route('promotions.go', campaign)`. |
| Deep links in the app | `POST ads.tap` records the click in the background and returns `{open}`; `ads.js` opens the status viewer / channel preview / community join sheet / business chat through the existing managers. No full-page reload. |

---

## A. Data model

Two additive migrations, written with the self-checking `add()/hasIndex()` helpers of `2026_10_02_000001_rework_ads_for_placements.php`:

- `2026_10_03_000001_create_monetisation.php` — new tables + `users` columns (creates `payments` before adding `subscriptions.payment_id`, so there is no circular-FK problem).
- `2026_10_03_000002_add_promotions_to_ads.php` — `ad_campaigns` columns.

Money is always **integer minor units** (`*_minor`; PKR 499.00 = 49900). Coins are integers.

### A.1 `plans`

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| name | string(60) | "Pro" |
| slug | string(40) unique | |
| description | string(200) null | |
| period | string(8) | `month` \| `year` |
| price_minor | unsignedInteger | in `currency` |
| currency | char(3) | copied from `paid_currency` at creation (admin may edit) |
| price_usd_minor | unsignedInteger null | PayPal price (PayPal has no PKR); null ⇒ PayPal hidden for this plan |
| ads_off | boolean default 0 | benefit |
| verified_badge | boolean default 0 | benefit |
| monthly_coins | unsignedInteger default 0 | benefit: free coins per month of the period |
| limits | json null | `{"upload_mb":64,"group_members":512,"broadcast_recipients":512,"storage_mb":2048}`; missing key = app default |
| play_product_id | string(80) null unique | consumable INAPP product id (`plan_pro_month`) |
| is_active | boolean default 1 | inactive = not purchasable; running subs continue |
| sort | unsignedSmallInteger default 0 | |
| timestamps | | |

### A.2 `coin_packs`

| column | type |
|---|---|
| id, name string(60), coins unsignedInteger, bonus_coins unsignedInteger default 0, price_minor, currency char(3), price_usd_minor null, play_product_id string(80) null unique, is_active, sort, timestamps | |

### A.3 `subscriptions`

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users cascadeOnDelete | |
| plan_id | fk plans restrictOnDelete | |
| payment_id | fk payments nullOnDelete null | null for admin grants |
| status | string(10) | `active` \| `queued` \| `expired` \| `cancelled` \| `revoked` |
| source | string(8) | `manual` \| `stripe` \| `paypal` \| `play` \| `admin` |
| benefits | json | **snapshot** of `{ads_off, verified_badge, monthly_coins, limits}` at purchase |
| starts_at, ends_at | timestamp | queued rows have `starts_at` in the future |
| coins_granted_periods | unsignedSmallInteger default 0 | monthly grants done (n) |
| reminded_at | timestamp null | "ends in 3 days" notification sent |
| ended_by | fk users nullOnDelete null | admin who revoked/cancelled |
| end_reason | string(60) null | `refund`, `admin`, `play_void` |
| timestamps | | |
| indexes | `(user_id, status)`, `(status, ends_at)`, `(status, starts_at)` | |

### A.4 `wallets` — one row per user, created lazily

| column | type | notes |
|---|---|---|
| user_id | fk users cascadeOnDelete, **primary** | |
| balance | unsignedInteger default 0 | spendable coins (all sources) |
| withdrawable | unsignedInteger default 0 | part of `balance` earned from referrals (TikTok "Earned"); invariant `withdrawable ≤ balance` |
| earned_total | unsignedInteger default 0 | lifetime referral coins |
| purchased_total | unsignedInteger default 0 | lifetime bought + plan coins + bonuses |
| spent_total | unsignedInteger default 0 | |
| frozen | boolean default 0 | admin freeze: no debits, no promotions; credits still land |
| updated_at | timestamp | |

Unsigned columns: a race that slips past the PHP check fails at MySQL (strict mode), never goes negative.

### A.5 `coin_transactions` — append-only ledger

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| user_id | fk users cascadeOnDelete | |
| type | string(24) | `purchase` \| `plan_coins` \| `referral` \| `referral_welcome` \| `referral_void` \| `promotion_hold` \| `promotion_refund` \| `badge` \| `payment_refund` \| `admin_adjust` |
| amount | integer (signed) | + credit / − debit; never 0 |
| withdrawable_delta | integer default 0 | how much of `amount` moved the withdrawable bucket (signed) |
| balance_after | unsignedInteger | |
| withdrawable_after | unsignedInteger | |
| reference_type | string(40) null | `Payment` \| `AdCampaign` \| `Referral` \| `Subscription` \| `User` |
| reference_id | unsignedBigInteger null | |
| idempotency_key | string(80) **unique NOT NULL** | see key table below |
| note | string(160) null | shown in History |
| meta | json null | e.g. `{"shortfall": 120, "rate": 100, "views": 2000}` |
| created_by | fk users nullOnDelete null | admin adjustments |
| created_at | timestamp | |
| indexes | `(user_id, created_at)`, `(reference_type, reference_id)` | |

Idempotency keys (the only writers are the services named):

| key | cause | writer |
|---|---|---|
| `payment:{id}` | coins credited for a fulfilled payment | `PaymentService::fulfil` |
| `payment:{id}:refund` | clawback on refund/void | `PaymentService::reverse` |
| `plan-coins:{sub}:{n}` | monthly free coins, period index n ≥ 0 | `PlanService` |
| `referral:{id}:referrer`, `referral:{id}:referred` | rewards | `ReferralService::rewardIfEligible` |
| `referral:{id}:void:referrer`, `referral:{id}:void:referred` | clawback on void | `ReferralService::void` |
| `promo:{campaign}:hold` | coins held at submission | `PromotionService::create` |
| `promo:{campaign}:refund` | full or unused refund (once) | `PromotionService::refund` |
| `badge:{user}:{clientToken}` | badge bought with coins | `BadgeService::buy` |
| `admin:{token}` | admin ± adjust (form carries a uuid `token`) | `CoinService::adjust` |

### A.6 `payments`

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| uuid | char(36) unique | Stripe idempotency key, `client_reference_id`, PayPal `PayPal-Request-Id` / `custom_id` |
| client_token | char(36) unique null | double-tap guard on `pay.begin` (returns the same pending payment) |
| user_id | fk users **nullOnDelete** null | anonymised on account deletion, amounts kept |
| purpose | string(8) | `plan` \| `coins` |
| plan_id | fk plans nullOnDelete null | |
| coin_pack_id | fk coin_packs nullOnDelete null | |
| subscription_id | fk subscriptions nullOnDelete null | set by fulfilment (the sub created/extended) |
| gateway | string(8) | `manual` \| `stripe` \| `paypal` \| `play` |
| status | string(10) | `pending` \| `review` \| `paid` \| `fulfilled` \| `failed` \| `rejected` \| `cancelled` \| `refunded` |
| platform | string(8) | `web` \| `android` (server-decided at start) |
| amount_minor, currency | unsignedInteger, char(3) | snapshot at start (USD for PayPal; Play: Google's `priceAmountMicros/1e4` + `priceCurrencyCode` in `meta`, `amount_minor` = admin price for reporting) |
| coins | unsignedInteger null | pack coins + bonus, snapshot |
| gateway_ref | string(191) null | Stripe session id / PayPal order id / `sha256(purchaseToken)` / `manual:{id}` |
| gateway_capture_ref | string(191) null | Stripe `payment_intent` / PayPal capture id / Play `orderId` |
| manual_method | string(12) null | `jazzcash` \| `easypaisa` \| `bank` |
| proof_path | string null | screenshot on the **`local`** disk (`storage/app/private/payments/{id}/…`, never public) |
| proof_ref | string(64) null | txn id typed by the user (not unique; duplicates flagged) |
| proof_note | string(160) null | |
| reviewed_by | fk users nullOnDelete null | |
| reviewed_at | timestamp null | |
| review_note | string(200) null | shown to the user on rejection |
| paid_at, fulfilled_at, refunded_at | timestamp null | |
| refund_ref | string(191) null | gateway refund id |
| meta | json null | trimmed gateway payload, `reason`, `shortfall`, `deleted_user` — never card data |
| timestamps | | |
| indexes | **unique** `(gateway, gateway_ref)`, `(user_id, status)`, `(status, created_at)` | the unique index is the Play replay + Stripe/PayPal duplicate guard |

### A.7 `payment_events` — webhook / callback idempotency

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| gateway | string(8) | |
| event_id | string(191) | Stripe `evt_…`; PayPal webhook `id`; PayPal capture `capture:{id}`; Play `void:{sha256(token)}` |
| type | string(80) | |
| payment_id | fk payments nullOnDelete null | |
| payload | json null | trimmed |
| processed_at | timestamp null | **null = received, not applied** |
| error | string(300) null | last processing error |
| created_at | | |
| unique | `(gateway, event_id)` | |

### A.8 `referrals`

| column | type | notes |
|---|---|---|
| id | bigint pk | |
| referrer_id | fk users cascadeOnDelete | |
| referred_id | fk users cascadeOnDelete, **unique** | one reward per referred account, ever |
| code | string(12) | code used (snapshot) |
| status | string(10) | `pending` \| `rewarded` \| `void` |
| void_reason | string(40) null | `self` \| `referrer_inactive` \| `ip_cap` \| `daily_cap` \| `disabled` \| `deleted_early` \| `admin` |
| referrer_coins, referred_coins | unsignedInteger default 0 | amounts at reward time |
| ip_hash | char(64) null | `sha256(ip . APP_KEY)` of the sign-up request |
| rewarded_at, voided_at | timestamp null | |
| created_at | | |
| indexes | `(referrer_id, status)`, `(ip_hash, created_at)` | |

### A.9 `users` (additive)

| column | type | notes |
|---|---|---|
| referral_code | char(8) unique null | `[A-Z2-9]{8}`, generated lazily by `ReferralService::codeFor()` |
| referred_by | fk users nullOnDelete null | set at sign-up only |
| plan_id | fk plans nullOnDelete null | denormalised current active plan; written only by `PlanService` under a user lock |
| plan_until | timestamp null | mirror of the active sub's `ends_at` |
| verified_until | timestamp null | coin/admin badge; `2099-12-31` = lifetime |
| verified_source | string(8) null | `coins` \| `admin` (plan badge is computed, not stored) |
| index | `(plan_until)` | |

`User` gains: `wallet(): HasOne`, `subscriptions(): HasMany`, `payments(): HasMany`, `promotions(): HasMany(AdCampaign, 'owner_id')`, `referrals(): HasMany(Referral, 'referrer_id')`, `referredBy(): BelongsTo`. New columns are **not** in `$fillable` (services use `forceFill`). Casts: `plan_until`, `verified_until` datetime. `booted()` gains the `updated` hook of B.6.

### A.10 `ad_campaigns` (additive — promotions are campaigns)

| column | type | notes |
|---|---|---|
| owner_id | fk users cascadeOnDelete null | null = house ad; set = user promotion |
| kind | string(12) default `house` | `house` \| `status` \| `channel` \| `community` \| `business` \| `card` \| `link` |
| target_type | string(20) null | `status` \| `conversation` \| `community` \| `user` |
| target_id | unsignedBigInteger null | |
| client_token | char(36) unique null | idempotent submit |
| review_status | string(10) null | null (house) \| `pending` \| `approved` \| `rejected` |
| review_note | string(200) null | |
| reviewed_by | fk users nullOnDelete null | |
| reviewed_at | timestamp null | |
| coins_spent | unsignedInteger default 0 | |
| coins_refunded | unsignedInteger default 0 | |
| rate_per_1000 | unsignedInteger null | rate snapshot |
| view_budget | unsignedInteger null | `intdiv(coins_spent * 1000, rate)`; null = unlimited (house) |
| completed_at | timestamp null | budget used or stopped |
| refunded_at | timestamp null | refund done once |
| stop_reason | string(40) null | `budget` \| `user` \| `admin` \| `target_gone` \| `rejected` \| `owner_deleted` |
| indexes | `(owner_id, created_at)`, `(review_status, created_at)`, `(kind, target_type, target_id)` | existing `(status, starts_at, ends_at)` still serves picking |

`AdCampaign` model changes: `STATUSES` **unchanged** (admin house-ad form validates against it); new `PROMO_STATUSES = ['pending', 'active', 'completed', 'stopped', 'rejected']`, `KINDS`; fillable += the new columns; casts `completed_at`, `refunded_at`, `reviewed_at`; `owner(): BelongsTo`; `isPromotion(): bool` (`owner_id !== null`); `isLive()` += `&& ($this->view_budget === null || $this->impressions < $this->view_budget)`; `acceptsTap(): bool` = `isLive() || ($this->isPromotion() && in_array($this->status, ['completed', 'stopped']))` (a tap on the last served card still counts); `remainingViews(): ?int`; `scopeHouse()` (`whereNull('owner_id')`), `scopePromotions()`; `payload()` adds `'kind'`, `'promoted' => isPromotion()`, `'sponsor' => owner name for promotions`, `'internal' => in_array(kind, [status, channel, community, business])`.

`ad_views`, `ad_stats`, `ad_placement_stats`: unchanged.

`admin_audit_logs`: no schema change. `AdminAuditLog::ACTIONS` += `plan.created`, `plan.updated`, `plan.deleted`, `coin_pack.created`, `coin_pack.updated`, `coin_pack.deleted`, `payment.approved`, `payment.rejected`, `payment.refunded`, `payment.retried`, `payment.proof_viewed`, `promotion.approved`, `promotion.rejected`, `promotion.stopped`, `subscription.granted`, `subscription.ended`, `coins.adjusted`, `wallet.frozen`, `badge.granted`, `badge.removed`, `referral.voided`, `paid.settings_updated`.

### A.11 `AppSetting::DEFAULTS` additions

| key | default | meaning |
|---|---|---|
| `paid_enabled` | `false` | master switch |
| `paid_currency` | `'PKR'` | default currency for new plans/packs, manual instructions, display |
| `promote_enabled` | `true` | Promote row + routes (needs master) |
| `promo_rate_status` / `_channel` / `_community` / `_business` / `_card` / `_link` | `100` / `100` / `100` / `120` / `150` / `200` | coins per 1,000 views |
| `promo_min_coins` | `50` | |
| `promo_max_coins` | `50000` | |
| `promo_max_active` | `5` | pending + active per user |
| `promo_daily_cap` | `2` | `per_user_daily_cap` for promotions |
| `promo_weight` | `2` | `weight` for promotions in `weightedPick` |
| `promo_auto_approve` | `false` | skip review for status/channel/community/business (never card/link) |
| `promo_placements` | `['chat_list','status_list','channels']` | placements promotions may use (∩ `AdPlacement::enabled()`) |
| `promo_blocked_hosts` | `null` | newline list of hosts refused for card/link |
| `referral_enabled` | `true` | |
| `referral_reward` | `50` | coins to the referrer (withdrawable) |
| `referral_welcome` | `0` | coins to the new user (not withdrawable); 0 = none |
| `referral_daily_cap` | `20` | rewards per referrer per day |
| `referral_ip_cap` | `3` | rewarded sign-ups per `ip_hash` per 7 days |
| `badge_coin_price` | `500` | 0 = not sold for coins |
| `badge_days` | `365` | 0 = lifetime |
| `wallet_withdraw_enabled` | `false` | Withdraw button "Coming soon" while false |
| `manual_enabled` | `true` | |
| `manual_jazzcash`, `manual_easypaisa`, `manual_bank` | `null` | instruction text per method; blank = method hidden |
| `manual_note` | `null` | text under the details |
| `manual_expire_hours` | `72` | pending manual payment without proof ⇒ cancelled |
| `proof_keep_days` | `90` | screenshot purge after final state |
| `stripe_enabled` | `false` | |
| `paypal_enabled` | `false` | |
| `play_enabled` | `false` | |
| `play_min_app_code` | `null` | app builds below this show "Update the app to buy" |

`AppConfigService::FIELDS` additions (secrets encrypted with `Crypt`, never echoed, never in audit meta; `config/services.php` gains matching `stripe`, `paypal`, `play` arrays with `env()` fallbacks):

| key | config | secret |
|---|---|---|
| `stripe_publishable_key` | `services.stripe.key` | no |
| `stripe_secret_key` | `services.stripe.secret` | **yes** |
| `stripe_webhook_secret` | `services.stripe.webhook_secret` | **yes** |
| `paypal_client_id` | `services.paypal.client_id` | no |
| `paypal_secret` | `services.paypal.secret` | **yes** |
| `paypal_mode` | `services.paypal.mode` (`options: sandbox, live`) | no |
| `paypal_webhook_id` | `services.paypal.webhook_id` | no |
| `play_package_name` | `services.play.package_name` (env default `com.hunario.chat`) | no |
| `play_service_account_json` | `services.play.service_account` | **yes** (textarea) |

---

## B. Services (`app/Services/`, gateway drivers in `app/Services/Payments/`)

Exceptions (flat, like `OtpException`): `App\Exceptions\InsufficientCoinsException` (→ 422 `{code:'insufficient_coins', needed, balance}`), `App\Exceptions\WalletFrozenException` (422), `App\Exceptions\PaymentException` (carries `code` + HTTP status: 409/422/503), `App\Exceptions\PromotionException` (422).

### B.1 `MonetisationService` (façade)

```php
public function enabled(): bool;                                   // paid_enabled
public function promoteEnabled(): bool;  public function referralEnabled(): bool;
public function currency(): string;
public function formatMoney(int $minor, ?string $currency = null): string;   // "Rs 1,200" / "$4.99"
public function platform(Request $request): string;               // 'android' when UA contains 'One2OneApp/', else 'web'
public function settingsFlags(User $user): array;                 // ['premium'=>bool,'wallet'=>bool,'promote'=>bool,'refer'=>bool] for profile/edit $sections
public function configFor(User $user, Request $request): array;   // AppConfigComposer 'paid' block (see G)
```

### B.2 `CoinService` — the only writer of `wallets`

```php
public function wallet(User $user): Wallet;                        // firstOrCreate, no lock (display)
public function summary(User $user): array;                        // {balance, withdrawable, earned_total, purchased_total, spent_total, frozen, withdraw_enabled}
public function credit(User $user, int $amount, string $type, ?Model $ref, string $key, ?string $note = null, bool $withdrawable = false, array $meta = [], ?User $by = null): CoinTransaction;
public function debit(User $user, int $amount, string $type, ?Model $ref, string $key, ?string $note = null, array $meta = [], ?User $by = null): CoinTransaction;   // throws InsufficientCoinsException / WalletFrozenException
public function refund(User $user, int $amount, string $type, Model $ref, string $key, CoinTransaction $originalDebit, ?string $note = null): CoinTransaction;
public function clawback(User $user, int $amount, string $type, ?Model $ref, string $key, string $note): CoinTransaction;   // never throws; debits min(amount, balance); meta.shortfall
public function adjust(User $admin, User $user, int $signedAmount, string $note, string $token): CoinTransaction;   // key admin:{token}; audit coins.adjusted
public function freeze(User $admin, User $user, bool $frozen): Wallet;   // audit wallet.frozen
public function history(User $user, int $perPage = 30): LengthAwarePaginator;
public function findByKey(string $key): ?CoinTransaction;
```

Behaviour (every mutation):
1. `DB::transaction(attempts: 3)` (deadlock retry). Nesting inside a caller's transaction becomes a savepoint.
2. `Wallet::query()->whereKey($user->id)->lockForUpdate()->first() ?? firstOrCreate` — the wallet row lock serialises all coin moves for that user.
3. **Inside the lock**: if a `coin_transactions` row with `idempotency_key` exists → return it, change nothing (this is how a replayed webhook, re-run command or double tap becomes a no-op). A `UniqueConstraintViolationException` on insert (should not happen under the lock) is caught and the existing row returned.
4. `amount <= 0` → `InvalidArgumentException`. Debit: frozen → `WalletFrozenException`; `balance < amount` → `InsufficientCoinsException` **before** writing. The `UPDATE wallets SET balance = balance - ?` on an unsigned column is the last line of defence.
5. Debit consumes purchased coins first: `withdrawable_delta = -max(0, amount - (balance - withdrawable))`. Credit with `$withdrawable=true` adds to `withdrawable` and `earned_total`; otherwise `purchased_total`.
6. `refund()` restores the withdrawable share proportionally: `share = intdiv(abs(original.withdrawable_delta) * amount, abs(original.amount))`, clamped to `[0, amount]`.
7. `clawback()`: `taken = min(amount, balance)`; if `taken > 0` write the debit row (withdrawable last, same rule); `meta.shortfall = amount - taken`; if `taken === 0` still write a zero-effect marker? No — `amount` never 0: write nothing but return a transient `CoinTransaction` with `meta.shortfall`; callers store the shortfall on the payment/referral `meta`.
8. Every row gets `balance_after`/`withdrawable_after` from the locked row after the update.

### B.3 `PlanService`

```php
public function purchasable(): Collection;                          // active plans by sort
public function activeFor(User $user): ?Subscription;               // status active, ends_at > now (memoised per request)
public function benefits(User $user): array;                        // active sub's snapshot or [] (memoised)
public function hasBenefit(User $user, string $key): bool;          // ads_off | verified_badge
public function activate(User $user, Plan $plan, string $source, ?Payment $payment = null, ?User $by = null): Subscription;
public function grant(User $admin, User $user, Plan $plan, int $days): Subscription;   // source admin; audit subscription.granted
public function revoke(Subscription $sub, string $reason, ?User $by = null): void;       // status revoked, ends_at=now, clears users.plan_*; audit subscription.ended when $by
public function expire(): int;                                      // scheduled hourly
public function grantMonthlyCoins(): int;                           // scheduled daily
public function remindEnding(): int;                                // scheduled daily: ends within 3 days, reminded_at null → notification
public function snapshot(Plan $plan): array;                        // {ads_off, verified_badge, monthly_coins, limits}
```

- `activate()`: `DB::transaction`; `User::whereKey()->lockForUpdate()`; idempotent per `payment_id` (a sub already referencing this payment is returned). Cases: no active sub → new `active` row `[now, now + period]`; active sub of the **same** plan → `ends_at += period` (renewal; row kept, `payment.subscription_id` = that row); active sub of a **different** plan → new row `queued` with `starts_at = old.ends_at`, `ends_at = starts_at + period` (UI: "starts on …"). Writes `users.plan_id/plan_until` for the active row only. Grants period 0 coins immediately (`plan-coins:{sub}:0`, `coins_granted_periods = 1`) for a new row; a renewal keeps counting from its own `starts_at`. Sends `MoneyNotification(plan_activated)`.
- `expire()`: per row `DB::transaction` + user lock: `active` with `ends_at <= now` → `expired`; `users.plan_id/plan_until = null` only if they still point at this sub's plan; then if a `queued` row for the user has `starts_at <= now` → it becomes `active` (users.plan_* updated, coins period 0 granted). Notification `plan_expired` (with "Renew"). No audit row (system).
- `grantMonthlyCoins()`: for `active` subs with `benefits.monthly_coins > 0`: `while (coins_granted_periods < monthsInPeriod && starts_at->addMonths(coins_granted_periods) <= now)` → `credit(key plan-coins:{sub}:{n})` then `coins_granted_periods++` in the same transaction. Idempotent across double runs (key) and catches up after missed days. Yearly = 12 grants.

### B.4 `LimitService` — effective limits per user

```php
public function for(User $user): array;                 // merged defaults + active snapshot limits (plan value wins only when larger)
public function uploadKb(User $user, string $type): int; // image|video|document|voice → max(config max_kb, upload_mb*1024)
public function groupMembers(User $user): int;
public function broadcastRecipients(User $user): int;
public function storageMb(User $user): int;             // 0 = unlimited
```

Named enforcement points (one line each): `GroupService::maxMembers(?User $for = null)` (creator's limit applies to the group: `create()`, `addMembers()`, `joinWithLink()` use `$group->creator`), `BroadcastService::maxRecipients(?User $for = null)`, `SendMessageRequest` upload `max:` rules read `LimitService::uploadKb($this->user(), $type)`, `StatusController@store` video/image rules likewise, storage quota: `SendMessageRequest::withValidator()` compares `StorageUsageService::usedBytes(User)` (new method: `SUM(attachment_size)` of the user's sent messages) + upload size with `storageMb()` when `> 0` (new `config('chat.storage.default_mb', env CHAT_STORAGE_MB, 0)`). `ChatConfigComposer` `limits` / `groups` blocks use the user's values so the JS pickers show the right numbers.

### B.5 `PromotionService`

```php
public const KINDS = ['status', 'channel', 'community', 'business', 'card', 'link'];
public function enabled(): bool;                                  // paid_enabled && promote_enabled
public function rates(): array;                                   // kind => coins per 1,000
public function quote(string $kind, int $coins): array;           // {rate, views: intdiv(coins*1000, rate), min, max}
public function targets(User $user): array;                       // what they can promote (see E.5), each {kind, id, title, subtitle, image, already_promoted}
public function placementsFor(string $kind): array;               // default per kind ∩ promo_placements ∩ AdPlacement::enabled()
public function create(User $user, array $data, ?UploadedFile $image): AdCampaign;
public function approve(User $admin, AdCampaign $promo, ?string $note = null): AdCampaign;
public function reject(User $admin, AdCampaign $promo, string $note): AdCampaign;     // full refund
public function stop(AdCampaign $promo, ?User $by, string $reason, bool $refund = true): AdCampaign;   // user/admin/system; unused refund
public function complete(AdCampaign $promo): void;                 // budget used; called inside AdService::countView
public function refund(AdCampaign $promo, bool $full): ?CoinTransaction;   // once, key promo:{id}:refund
public function stopForTarget(string $targetType, int $targetId, string $reason): int;   // status expired / channel|community|business deleted
public function stopAllFor(User $user, string $reason): int;      // account deletion
public function sweepTargets(): int;                              // scheduled: status promos whose Status is gone/expired, channels ended, communities deleted, business profile removed
public function listFor(User $user): Collection;                  // rows with impressions, clicks, ctr, view_budget, remaining, status, review_note, coins
public function stats(AdCampaign $promo): array;                  // {views, clicks, ctr, budget, remaining, spent, refunded, days: 30 from ad_stats, placements: ad_placement_stats, status, review_note}
public function openPayload(AdCampaign $promo, User $viewer): ?array;   // {type, ...} for ads.tap / promotions.go (D.5)
public function grantsStatusView(Status $status, User $viewer): bool;  // approved+active/completed promotion exists for this status
```

`create()`:
1. Validates: enabled; wallet not frozen; `kind` in KINDS; `coins` within `promo_min_coins..promo_max_coins`; count of the user's `pending`+`active` promotions < `promo_max_active`; ownership — `status`: `Status::active()->where('user_id')`, `channel`: `Conversation::TYPE_CHANNEL` where `isAdmin($user)` and `ended_at` null, `community`: announcement group `isAdmin($user)`, `business`: `businessProfile()->exists()`; a status/channel/community/business with a `pending|active` promotion → 422 `already_promoted`; card/link: `url:http,https`, host not in `promo_blocked_hosts`, no private/loopback IP (reuse `SafeFetcher` rules from `LinkPreviewService`), image `png,jpg,jpeg,webp` ≤ 3 MB via `ImageService::reencode()` stored under `ads/promo/` on the `public` disk. Text limits as house ads (title 80, body 200, CTA 24).
2. Builds the campaign from the target (E.5) with `owner_id`, `kind`, `target_*`, `client_token`, `placements = placementsFor(kind)`, `weight = promo_weight`, `per_user_daily_cap = promo_daily_cap`, `countries/segments/min_age/max_age/gender = null`, `ends_at = status.expires_at` for `status`, `rate_per_1000`, `coins_spent`, `view_budget = quote().views`, `status = 'pending'`, `review_status = 'pending'` — or `status='active', review_status='approved', starts_at=now` when `promo_auto_approve` and kind ∉ {card, link}.
3. `DB::transaction`: if a campaign with this `client_token` exists for the owner → return it; **insert the campaign**, then `CoinService::debit(user, coins, 'promotion_hold', $campaign, "promo:{$campaign->id}:hold")`. Debit throws → rollback → no row, 422 with a "Get coins" CTA.
4. `MoneyNotification(promotion_submitted)`; the admin nav badge counts `review_status = pending`.

`approve()`: lock row, only from `pending`; `status=active, review_status=approved, starts_at=now, reviewed_*`; audit `promotion.approved`; notify. `reject()`: only from `pending`; `status=rejected, review_status=rejected, stop_reason=rejected, review_note`; `refund(full: true)`; audit; notify with the note. `stop()`: from `active` (or `pending` by the user = withdraw, full refund): `status=stopped, completed_at=now, stop_reason`; `refund(full: false)` unless `$refund=false` (admin "policy breach — no refund"); audit `promotion.stopped` when an admin did it. `complete()`: `UPDATE … SET status='completed', completed_at=now, stop_reason='budget' WHERE id=? AND status='active'`; notification "Your promotion finished: N views, M taps". `refund()`: lock, if `refunded_at` set → null; `amount = full ? coins_spent : max(0, coins_spent - (int) ceil(impressions * rate_per_1000 / 1000))`, clamped `≤ coins_spent`; if `amount > 0` → `CoinService::refund(owner, amount, 'promotion_refund', $promo, "promo:{id}:refund", originalDebit: findByKey("promo:{id}:hold"))`; set `coins_refunded`, `refunded_at` (also set when amount is 0, so it never re-runs).

### B.6 `ReferralService`

```php
public function enabled(): bool;                               // paid_enabled && referral_enabled
public function codeFor(User $user): string;                   // lazily writes users.referral_code (8 chars from [A-Z2-9], retry on unique clash)
public function link(User $user): string;                      // route('referral.join', code)
public function resolve(?string $code): ?User;                 // active, non-banned owner
public function attach(User $newUser, ?string $code, Request $request): ?Referral;   // at sign-up only: creates pending row + users.referred_by; never rewards
public function rewardIfEligible(User $referred): ?Referral;   // the ONLY place rewards are paid
public function void(Referral $referral, string $reason, ?User $by = null): void;    // clawback both credits; audit referral.voided when admin
public function voidForDeletedAccount(User $referred): void;   // AccountDeletionService: rewarded < 7 days ago → void('deleted_early')
public function summary(User $user): array;                    // {code, link, reward, welcome, invited, rewarded, pending, coins_earned, list: [{name, status, date}]}
```

- `attach()`: no-op (silently) when disabled, code unknown, `resolve()` null, or self (`referrer->id === newUser->id` or same phone). Stores `ip_hash = hash('sha256', $request->ip().config('app.key'))`.
- Hook — in `User::booted()` (no Observer class; the model already has a `saving` hook):
  ```php
  static::updated(function (User $user) {
      if ($user->wasChanged('phone_verified_at') && $user->phone_verified_at !== null && $user->getOriginal('phone_verified_at') === null) {
          app(ReferralService::class)->rewardIfEligible($user);
      }
  });
  ```
  This covers `PhoneLoginController::verify()` (line 110–111), `AccountService::changePhone(verified: true)` (line 121, from `PhoneChangeController::verify`) and any admin edit that later re-verifies. (`getOriginal()` is still the pre-save value inside `updated`.)
- `rewardIfEligible()`: `DB::transaction`; `Referral::where('referred_id')->lockForUpdate()->first()`; only `pending` proceeds; checks in order → `void` with reason: disabled (`disabled`), referrer not `active`/banned (`referrer_inactive`), referrer == referred (`self`), rewarded rows with the same `ip_hash` in the last 7 days ≥ `referral_ip_cap` (`ip_cap`), referrer's rewarded rows today ≥ `referral_daily_cap` (`daily_cap`). Success: `credit(referrer, referral_reward, 'referral', $ref, "referral:{id}:referrer", withdrawable: true)`, `credit(referred, referral_welcome, 'referral_welcome', …, "referral:{id}:referred")` when > 0, `status=rewarded`, snapshots amounts; notifications to both. A second `null→set` transition on the same user finds `rewarded` → no-op.
- `void()`: `rewarded` → `void`; `clawback(referrer, referrer_coins, 'referral_void', $ref, "referral:{id}:void:referrer", note)` and the same for the referred (welcome coins); shortfall stored in a `meta` column? — `referrals` has no meta; the shortfall lives on the ledger rows' `meta`. Note: `void()` on a `pending` row just marks it.

### B.7 `BadgeService`

```php
public function isVerified(User $user): bool;      // (verified_until?->isFuture() ?? false) || PlanService::hasBenefit($user,'verified_badge'); memoised per request
public function price(): int;  public function days(): int;
public function state(User $user): array;          // {verified, source: plan|coins|admin|null, until, purchasable, price, days}
public function buy(User $user, string $clientToken): User;    // debit badge_coin_price key badge:{user}:{clientToken}; verified_until = max(now, verified_until) + badge_days (2099-12-31 when days=0); verified_source=coins
public function grant(User $admin, User $user, ?int $days): User;   // source admin; audit badge.granted
public function remove(User $admin, User $user): User;              // verified_until=null; audit badge.removed (plan badge cannot be removed except by ending the plan)
```

`buy()` refuses (409 `already_lifetime`) when `verified_until` is lifetime; a repeat with the **same** token returns the existing ledger row (no second charge); a new token extends. Price 0 → 404.

### B.8 `PaymentService` + drivers

```php
public function methodsFor(User $user, Request $request, Plan|CoinPack|null $item = null): array;   // android: ['play'] if play_enabled && item.play_product_id; web: manual (if enabled and any manual_* text), stripe, paypal (needs price_usd_minor); [] when !paid_enabled
public function begin(User $user, string $purpose, Plan|CoinPack $item, string $gateway, string $clientToken, Request $request): array;   // → ['payment' => Payment, 'redirect' => ?url, 'instructions' => ?array]
public function submitProof(Payment $p, string $method, string $ref, ?string $note, UploadedFile $shot): Payment;   // manual pending → review
public function markPaid(Payment $p, array $gateway): Payment;    // pending|review → paid (own transaction, lockForUpdate); sets gateway refs, paid_at, meta; idempotent when already paid/fulfilled
public function fulfil(Payment $p): Payment;                       // paid → fulfilled (own transaction, lockForUpdate): coins → CoinService::credit(key payment:{id}); plan → PlanService::activate(); sets subscription_id, fulfilled_at; notifies; idempotent
public function settle(Payment $p, array $gateway): Payment;       // markPaid then fulfil; fulfil exception leaves 'paid' (reported, admin Retry)
public function approve(Payment $p, User $admin, ?string $note): Payment;   // manual review → settle(gateway_ref "manual:{id}"); audit payment.approved
public function reject(Payment $p, User $admin, string $note): Payment;     // review|pending → rejected; audit; notify
public function fail(Payment $p, string $reason, array $meta = []): Payment;
public function cancel(Payment $p, string $reason): Payment;                // pending → cancelled
public function reverse(Payment $p, string $reason, ?User $admin = null, ?string $note = null, bool $viaGateway = false): Payment;   // fulfilled → refunded
public function retry(Payment $p, User $admin): Payment;                    // paid → fulfil(); audit payment.retried
public function expirePending(): int;                                       // scheduled
public function purgeProofs(): int;                                         // scheduled: proof files older than proof_keep_days after final state
public function anonymiseFor(User $user): void;                             // AccountDeletionService: delete proof files, meta.deleted_user=true (user_id nulls via FK)
public function recordEvent(string $gateway, string $eventId, string $type, array $payload, ?Payment $p): PaymentEvent;   // firstOrCreate; race-safe
public function processEvent(PaymentEvent $event, Closure $handler): void;  // lock event; skip if processed_at; run; set processed_at — or set error and rethrow
```

- `begin()`: 404 when `!paid_enabled`; 422 `gateway_unavailable` when `$gateway ∉ methodsFor()` (this is the platform refusal: manual/stripe/paypal from the app UA, play from web, PayPal without USD price); item inactive → 422; a pending payment with the same `client_token` → returned unchanged; older `pending` payments of this user for the same item+gateway are cancelled (`review` ones kept). Snapshots `amount_minor/currency/coins/platform/uuid`. Delegates to `driver->start()`.
- `reverse()`: `viaGateway` for stripe/paypal calls `driver->refund()` first (throws ⇒ nothing changes); coins → `CoinService::clawback(user, coins, 'payment_refund', $p, "payment:{id}:refund", note)` (shortfall → `meta.shortfall`, shown on the person page and dashboard); plan → `PlanService::revoke(sub, reason)`; `status=refunded`, `refund_ref`, `meta.reason`; audit `payment.refunded` (with `{reason, shortfall}`) when an admin did it; notify the user.
- `expirePending()`: manual `pending` older than `manual_expire_hours` → `cancel('no_proof')`; stripe/paypal `pending` older than 24 h → `cancel('abandoned')` (Stripe sessions die at 24 h anyway). `review` never auto-expires.

Interface `App\Services\Payments\PaymentGateway`:
```php
public function key(): string;
public function available(): bool;                                      // *_enabled + required keys present
public function start(Payment $payment): array;                         // ['redirect' => url] | ['instructions' => …] | []
public function refund(Payment $payment, ?string $note): ?string;       // gateway refund id; PaymentException when unsupported
```

- **`ManualGateway`**: `start()` → `['instructions' => {methods: {jazzcash: text, …}, note, amount, currency}]`; no external calls.
- **`StripeGateway`** (`stripe/stripe-php`): `start()` → `Checkout\Session::create(['mode'=>'payment','line_items'=>[price_data{currency, unit_amount: amount_minor, product_data{name}}], 'client_reference_id'=>uuid, 'metadata'=>{payment_id}, 'success_url'=>route('pay.return',[payment, 'gateway'=>'stripe']), 'cancel_url'=>…, 'expires_at'=>now+24h], ['idempotency_key'=>uuid])` → `gateway_ref = session id`, redirect. `handleWebhook(string $rawBody, string $sigHeader)`: `Webhook::constructEvent()` (300 s tolerance; `SignatureVerificationException` ⇒ 400, no row); `recordEvent('stripe', $evt->id, …)`; `processEvent()`: `checkout.session.completed` / `checkout.session.async_payment_succeeded` → find payment by `gateway_ref = session.id` **and** `uuid = client_reference_id` (unknown ⇒ processed, 200); require `payment_status === 'paid'`, `amount_total === amount_minor`, `strtolower(currency) === strtolower(payment.currency)` — mismatch ⇒ `fail('amount_mismatch')` + dashboard flag, processed, 200; else `settle(gateway_capture_ref = payment_intent)`; `checkout.session.expired` / `async_payment_failed` → `cancel`/`fail`; `charge.refunded` → `reverse('stripe_refund', viaGateway:false)`; other types ⇒ processed, ignored. `sync(Payment)`: server-side `Session::retrieve()` used by `pay.show` polling when the webhook has not arrived after 20 s — same checks, same `settle()`. `refund()` → `Refund::create(['payment_intent'=>gateway_capture_ref])`.
- **`PayPalGateway`** (`Http::` client, Orders v2; OAuth client-credentials token cached 8 h; base URL by `paypal_mode`): `start()` → `POST /v2/checkout/orders` `{intent: CAPTURE, purchase_units: [{reference_id: payment id, custom_id: uuid, amount: {currency_code: 'USD', value: price_usd_minor/100}}], payment_source.paypal.experience_context: {return_url: route('pay.return',[payment,'gateway'=>'paypal']), cancel_url}}` with header `PayPal-Request-Id: uuid` → `gateway_ref = order id`, redirect to the `payer-action` link. `capture(Payment $p, string $orderId)`: guard `orderId === gateway_ref`; `POST /v2/checkout/orders/{id}/capture` with `PayPal-Request-Id: uuid`; `422 ORDER_ALREADY_CAPTURED` ⇒ `GET /v2/checkout/orders/{id}` and continue; require `status === 'COMPLETED'`, capture `amount.value/currency_code` equal to the snapshot; `INSTRUMENT_DECLINED` ⇒ back to the approval link; `ORDER_NOT_APPROVED` ⇒ stays pending; else `recordEvent('paypal', "capture:{captureId}")` + `settle(gateway_capture_ref = capture id)`. Webhook (optional, when `paypal_webhook_id` set): verified via `POST /v1/notifications/verify-webhook-signature`; `PAYMENT.CAPTURE.COMPLETED` (belt-and-braces, idempotent), `PAYMENT.CAPTURE.REFUNDED` → `reverse`. `refund()` → `POST /v2/payments/captures/{id}/refund`.
- **`PlayGateway`** + `App\Services\Payments\GoogleServiceAccount` (RS256 JWT + token exchange, copied from `PushService::signedJwt()`/`accessToken()` with scope `https://www.googleapis.com/auth/androidpublisher`, token cached):
  ```php
  public function accountHash(User $user): string;                    // hash('sha256', $user->id . config('app.key'))
  public function products(): array;                                  // [{productId, purpose, item_id}] for active packs/plans with play_product_id
  public function verify(User $user, string $productId, string $purchaseToken, ?string $orderId, Request $request): array;   // → ['status' => 'fulfilled'|'pending', 'payment' => Payment]
  public function voidedPurchases(Carbon $since): array;               // GET purchases/voidedpurchases
  public function check(): array;                                      // admin Test connection: inappproducts.list
  ```
  `verify()` steps: (1) `hash = sha256(token)`; existing payment with `(play, hash)`: same user ⇒ return it (`fulfilled` ⇒ 200 idempotent, the app may consume; `paid` ⇒ `fulfil()` then 200; `pending` ⇒ continue to re-check), **other user ⇒ 409 `token_other_account`** (logged). (2) Resolve `productId` → Plan/CoinPack by `play_product_id` (else 422 `unknown_product`). (3) `GET androidpublisher/v3/applications/{play_package_name}/purchases/products/{productId}/tokens/{token}`; Google 5xx/timeout ⇒ **503 `google_unavailable`** (nothing written, app must not consume). (4) Require `purchaseState === 0` (1 ⇒ 422 `cancelled`; 2 ⇒ create/keep a `pending` payment, 202 `pending`); `consumptionState === 0` unless a fulfilled payment already exists (⇒ 422 `consumed`, logged for admin); `obfuscatedExternalAccountId === accountHash(user)` (⇒ 422 `account_mismatch`); `orderId` not already a `gateway_capture_ref` (⇒ 422 `order_reused`). (5) Create the payment (`gateway play`, `platform android`, snapshot, `gateway_ref = hash`, `gateway_capture_ref = orderId`, `meta.google = {priceAmountMicros, priceCurrencyCode, purchaseTimeMillis}`) — a unique-index violation here means a concurrent verify won: re-read and return it. (6) `settle()`. (7) `POST …/tokens/{token}:acknowledge` (best effort; the app's consume also acknowledges). Return 200 `{status:'fulfilled', wallet: summary}`.
  `refund()` ⇒ `PaymentException` ("Refund in Play Console; the daily check reverses it, or press Reverse now").

### B.9 Notifications

One `App\Notifications\MoneyNotification($type, array $data)` (`via: database + broadcast`, like `NewMessageNotification`; payload `{type, title, body, url}`) for: `coins_credited`, `payment_approved`, `payment_rejected`, `payment_refunded`, `promotion_approved`, `promotion_rejected`, `promotion_finished`, `promotion_stopped`, `plan_activated`, `plan_ending`, `plan_expired`, `referral_rewarded`, `badge_activated`. The notification centre renders them with a coin/crown icon and opens the settings tab in `url`.

### B.10 Console commands (`routes/console.php`, closure commands, all `withoutOverlapping()`)

| command | schedule | body |
|---|---|---|
| `chat:expire-plans` | hourly | `PlanService::expire()` (+ activates queued rows) |
| `chat:plan-coins` | daily 00:10 | `PlanService::grantMonthlyCoins()`, `PlanService::remindEnding()` |
| `chat:payments-sweep` | every 15 min | `PaymentService::expirePending()`, `purgeProofs()` |
| `chat:promotions-sweep` | every 5 min | `PromotionService::sweepTargets()` |
| `chat:play-sweep` | daily 03:00 | `PlayGateway::voidedPurchases(since: last run − 2 days)` → for each token hash with a `fulfilled` payment → `PaymentService::reverse(p, 'play_void')` (key `void:{hash}` in `payment_events`) |
| `chat:referral-codes` | manual | backfill `users.referral_code` |

`ChatDoctor` gains a "Paid features" section: master switch state, gateways enabled but unconfigured, last `chat:expire-plans` run (cache key like `SCHEDULER_HEARTBEAT_KEY`).

---

## C. Routes (`routes/web.php`)

Middleware `paid` = new `App\Http\Middleware\EnsurePaidEnabled` (alias in `bootstrap/app.php`; 404 unless `paid_enabled`). All user routes sit inside the existing `Route::middleware(['auth', 'active'])` group. Money POSTs use the existing `throttle:chat-lock` (5/min per route per user); reads use `chat-actions`/`chat-search`. Webhooks: outside `auth`, CSRF-exempt through `$middleware->validateCsrfTokens(except: ['webhooks/*'])` in `bootstrap/app.php`, inline `throttle:120,1`.

### C.1 User — settings sections (all `paid`)

| method | path | name | controller@method | extra |
|---|---|---|---|---|
| GET | `/settings/premium` | `premium.show` | `PremiumController@show` | JSON: plans, active/queued sub, benefits, badge state, methods, play products |
| GET | `/settings/wallet` | `wallet.show` | `WalletController@show` | JSON: summary, packs, methods, pending payments, play, history page 1 |
| GET | `/settings/wallet/history` | `wallet.history` | `WalletController@history` | `throttle:chat-search` |
| POST | `/settings/wallet/withdraw` | `wallet.withdraw` | `WalletController@withdraw` | 409 `{code:'coming_soon'}` unless `wallet_withdraw_enabled` |
| POST | `/settings/badge` | `badge.buy` | `BadgeController@buy` | `throttle:chat-lock`; body `client_token` |
| GET | `/settings/promote` | `promotions.index` | `PromotionController@index` | targets, rates, placements, my promotions; 404 when `!promote_enabled` |
| GET | `/settings/promote/quote` | `promotions.quote` | `PromotionController@quote` | `?kind&coins`, `throttle:chat-actions` |
| POST | `/settings/promote` | `promotions.store` | `PromotionController@store` | multipart (card image), `throttle:chat-lock` |
| GET | `/settings/promote/{campaign}` | `promotions.show` | `PromotionController@show` | owner only (404) |
| POST | `/settings/promote/{campaign}/stop` | `promotions.stop` | `PromotionController@stop` | `throttle:chat-actions` |
| GET | `/promote/{campaign}/go` | `promotions.go` | `PromotionController@go` | web fallback opener (auth only, no `paid`): renders `chat.index` with `openTarget` |
| GET | `/settings/refer` | `referral.show` | `ReferralController@show` | 404 when `!referral_enabled` |
| POST | `/ads/{campaign}/tap` | `ads.tap` | `AdController@tap` | `throttle:chat-actions`; not behind `paid` (house ads too) |

### C.2 User — payments (`paid`)

| method | path | name | controller@method | extra |
|---|---|---|---|---|
| POST | `/pay` | `pay.begin` | `PaymentController@begin` | `{purpose, item_id, gateway, client_token}`; `throttle:chat-lock`; 422 for a gateway not offered on this platform |
| GET | `/pay/{payment}` | `pay.show` | `PaymentController@show` | owner only; JSON status + manual instructions; calls `StripeGateway::sync()` when stripe pending > 20 s |
| POST | `/pay/{payment}/proof` | `pay.proof` | `PaymentController@proof` | multipart `{method, ref, note, screenshot}`; `throttle:chat-lock` |
| GET | `/pay/{payment}/return` | `pay.return` | `PaymentController@return` | Stripe success/cancel; PayPal return (`?token=` order id ⇒ `capture()`); then redirects to `profile.edit?tab=wallet|premium&payment=id` with a toast; **never credits from the redirect itself** |
| DELETE | `/pay/{payment}` | `pay.cancel` | `PaymentController@cancel` | pending only |
| POST | `/pay/play/verify` | `pay.play.verify` | `PaymentController@verifyPlay` | `{product_id, purchase_token, order_id}`; `throttle:chat-lock`; 422 from a web UA; also the restore path (same body per purchase) |

### C.3 Public

| method | path | name | controller@method | extra |
|---|---|---|---|---|
| GET | `/r/{code}` | `referral.join` | `ReferralController@join` | `throttle:60,1`; `where('code', '[A-Z2-9]{8}')`; stores `referral_code` in session (7 days) + cookie `ref` (30 days); guest ⇒ `register?ref=CODE`, signed-in ⇒ `chat.index` |
| POST | `/webhooks/stripe` | `webhooks.stripe` | `WebhookController@stripe` | no auth, CSRF-exempt, `throttle:120,1`; raw body via `$request->getContent()` |
| POST | `/webhooks/paypal` | `webhooks.paypal` | `WebhookController@paypal` | same |
| GET | `/refunds` | existing `legal` | `LegalController@show` | `PAGES` += `'refunds' => 'Refund policy'` (Play requires a reachable one) |

`register` (existing): `RegisterRequest` += `'ref' => ['nullable', 'string', 'size:8', 'regex:/^[A-Z2-9]{8}$/']`; the form has a hidden/optional `ref` input prefilled from the session; `AuthController::register()` calls `ReferralService::attach($user, $request->input('ref') ?: session('referral_code'), $request)` after `AccountService::register()`.

### C.4 Admin (inside `prefix('admin')->name('admin.')->middleware('admin')`)

| method | path | name | controller@method |
|---|---|---|---|
| GET | `/money` | `money` | `Admin\MoneyController@index` (overview) |
| GET | `/plans` · `/plans/new` · `/plans/{plan}` | `plans`, `plans.create`, `plans.edit` | `Admin\PlanController@index/create/edit` |
| POST · PUT · DELETE | `/plans` · `/plans/{plan}` | `plans.store`, `plans.update`, `plans.destroy` | destroy refused (422) while subscriptions reference it → "deactivate instead" |
| GET | `/coin-packs` | `coin-packs` | `Admin\CoinPackController@index` (inline table + add row) |
| POST · PUT · DELETE | `/coin-packs` · `/coin-packs/{pack}` | `coin-packs.store/update/destroy` | destroy refused while payments reference it |
| GET | `/payments` | `payments` | `Admin\PaymentController@index` (filters status/gateway/purpose/user/date; default `review`) |
| GET | `/payments/{payment}` | `payments.show` | `@show` |
| GET | `/payments/{payment}/proof` | `payments.proof` | `@proof` (streams from the `local` disk; audit `payment.proof_viewed`) |
| POST | `/payments/{payment}/approve` · `/reject` · `/refund` · `/retry` | `payments.approve/reject/refund/retry` | note required for reject/refund |
| GET | `/promotions` | `promotions` | `Admin\PromotionController@index` (tabs pending/active/completed/rejected) |
| GET | `/promotions/{campaign}` | `promotions.show` | `@show` |
| POST | `/promotions/{campaign}/approve` · `/reject` · `/stop` | `promotions.approve/reject/stop` | reject note required; stop has `refund` checkbox |
| GET | `/subscriptions` | `subscriptions` | `Admin\SubscriptionController@index` |
| POST | `/users/{user}/plan` | `users.plan.grant` | `Admin\SubscriptionController@grant` (`plan_id`, `days`) |
| DELETE | `/users/{user}/plan/{subscription}` | `users.plan.end` | `@end` (note) |
| POST | `/users/{user}/coins` | `users.coins.adjust` | `Admin\WalletController@adjust` (`amount` ±, `note`, `token`) |
| POST | `/users/{user}/wallet/freeze` | `users.wallet.freeze` | `@freeze` (`frozen` bool) |
| POST · DELETE | `/users/{user}/badge` | `users.badge.grant`, `users.badge.remove` | `Admin\BadgeController@grant/@remove` |
| GET | `/referrals` | `referrals` | `Admin\ReferralController@index` |
| POST | `/referrals/{referral}/void` | `referrals.void` | `@void` (note) |
| POST | `/settings/pay-check/{gateway}` | `settings.pay-check` | `Admin\PaymentController@check` (`throttle:6,1`; Stripe `Balance::retrieve`, PayPal token, Play `inappproducts.list`) |
| PUT | `/settings` | existing `settings.update` | `SystemController@updateSettings` gains the paid fields under a `paid_section` hidden marker |

Person page: `AdminController::USER_TABS` += `'money' => ['Money', 'coins']` with `admin/users/tabs/money.blade.php`.

### C.5 Route registration for JS

- `AppConfigComposer` `$routes` += `premium`, `wallet`, `walletHistory`, `walletWithdraw`, `badgeBuy`, `promotions`, `promotionsQuote`, `promotionsStore`, `promotionShow` (template `__ID__`), `promotionStop`, `referral`, `payBegin`, `payShow`, `payProof`, `payCancel`, `payPlayVerify` (only when `Route::has()`; the settings page reads `App.config.routes`).
- `ChatConfigComposer` `$optional` += `'adsTap' => ['ads.tap', ['campaign' => $id]]`, `'promotionsGo' => ['promotions.go', ['campaign' => $id]]`, `'statusesIndex'` unchanged.

---

## D. Serving integration (promotions through `AdService` / `AdPlacement`)

**D.1 Eligibility** — `AdService::pickForUser()` gets an early return and two query clauses:

```php
if (app(PlanService::class)->hasBenefit($user, 'ads_off')) {
    return null;                                    // ads off = no house ads and no promotions
}
$eligible = AdCampaign::query()
    ->where('status', 'active')
    ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('review_status', 'approved'))
    ->where(fn ($q) => $q->whereNull('view_budget')->orWhereColumn('impressions', '<', 'view_budget'))
    ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $user->getKey()))
    // …existing starts_at / ends_at clauses, ->get()->filter(matches) ->values()
```

`matches()` is unchanged: promotions carry `placements = placementsFor(kind)`, `per_user_daily_cap = promo_daily_cap`, `weight = promo_weight`, no targeting. House ads (`owner_id` null, `view_budget` null, `review_status` null) are picked exactly as today. Default placements per kind: `status` → `status_list, chat_list`; `channel`/`community` → `channels, chat_list`; `business`/`card`/`link` → `chat_list`; always ∩ `promo_placements` ∩ `AdPlacement::enabled()` — never `calls`/`chat_top` unless the admin adds them to `promo_placements`.

**D.2 Budget decrement and stop** — in `countView()`'s existing transaction, the impression branch becomes:

```php
$counted = AdCampaign::query()->whereKey($campaign->id)
    ->where(fn ($q) => $q->whereNull('view_budget')->orWhereColumn('impressions', '<', 'view_budget'))
    ->increment('impressions');                     // affected rows: 1 counted, 0 = budget used up concurrently
if ($counted === 0) {
    return;                                         // no ad_stats, no ad_placement_stats, no ad_views row
}
$this->stat(...); $this->placementStat(...); $view->views = ...; $view->save();
if ($campaign->view_budget !== null) {
    $fresh = AdCampaign::query()->whereKey($campaign->id)->first(['id', 'impressions', 'view_budget', 'status']);
    if ($fresh->impressions >= $fresh->view_budget) {
        $this->promotions->complete($fresh);        // status=completed, completed_at — SAME transaction as the Nth impression
    }
}
```

The conditional `UPDATE` serialises concurrent impressions on the row; the (N+1)th never lands. The promotion is marked `completed` by the transaction that counted the last view (never by a later pick, which `pickForUser` would already filter out). Over-delivery is exactly 0; the pick-vs-count race only ever under-serves (the row is not counted, not billed).

**D.3 Clicks** are never budgeted. `countView(clicked: true)` runs unchanged. `AdController::click` (`ads.click`, GET redirect) and the new `AdController::tap` use `$campaign->acceptsTap()` instead of `isLive()`, so a tap on the last served card of a just-completed promotion still records.

**D.4 Other stop conditions**: user Stop / admin Stop / reject (`PromotionService::stop/reject`); `ends_at` (status kind = `status.expires_at`, honoured by the existing query); target gone — `StatusService::expire()` calls `PromotionService::stopForTarget('status', id, 'target_gone')` before deleting each status; `ChannelService::delete()`/`destroy()`, `CommunityService::destroy()`, `BusinessController@destroy` and admin `SpaceController@destroyChannel/destroyCommunity/destroyStatus` call `stopForTarget(...)`; `chat:promotions-sweep` catches anything missed (targets that no longer exist / channels with `ended_at`). Each stop refunds the unused share once.

**D.5 The tap in the app** — `POST ads.tap {placement}` → `recordClick()` → JSON:

| kind | `open` returned by `PromotionService::openPayload()` | `ads.js` action |
|---|---|---|
| `status` | `{type:'status', user: UserResource(owner), status: StatusService payload}` (visibility granted by `grantsStatusView()`; `StatusController@media/@view` accept it too) | `chat.statuses.openViewer([{user, statuses:[status]}], 0, 0)` |
| `channel` | `{type:'channel', id}` | `chat.channels.preview(id)` |
| `community` | `{type:'community', invite: CommunityService::invitePreview(token)}` | `chat.communities.offerToJoin(invite)` |
| `business` | `{type:'business', user_id}` | `chat.startConversationWith(user_id)` then `chat.contactInfo.open(conversation.id)` |
| `card` / `link` / house | `{open: null, url: target_url}` | `window.open(url, '_blank', 'noopener')` (native shell hands external hosts to the browser as today) |

`ads.js` `card()` renders `promoted ? 'Promoted · {sponsor}' : 'Sponsored{ · sponsor}'`; for `internal` ads the anchor keeps `href=ad.click` as the no-JS fallback but the click handler `preventDefault()`s, posts `ads.tap` (fire-and-forget) and opens in-app; external kinds keep `target="_blank" rel="noopener nofollow sponsored"`. `promotions.go` (web fallback / admin preview link) renders `chat.index` with `openTarget` (same shape as `channelInvite`), and `ChatApp` boot passes it to the same `openPromoted(target)` function.

**D.6 Stats the user sees** — `PromotionService::stats()`: `impressions` (views), `clicks`, CTR, `view_budget`, `remaining`, `coins_spent`, `coins_refunded`, status chip + `review_note`, last-30-days bars from `ad_stats`, where-it-was-seen from `ad_placement_stats`. Same rows the admin sees; `ad_views` (viewer identities) is never exposed.

**D.7 Ads-off everywhere** — `ChatConfigComposer` sets `'ads' => $adsEnabled && ! $plans->hasBenefit($user, 'ads_off') ? [...] : ['enabled' => false]`, so `AdsManager` (and the AdMob/AdSense hooks it gates) never starts for ad-free users.

**D.8 House-ad regression safety** — `Admin\AdController@index` adds `->house()`; `AdCampaign::STATUSES` untouched (admin form `Rule::in`); null budget serves forever; existing `AdsTest` keeps passing.

---

## E. Flows

**E.1 Sign-up with referral.** Friend opens `/r/ABCD2345` → session `referral_code` + cookie → `/register?ref=…` shows "Invited by Ali" (hidden `ref`) → `AuthController::register` → `AccountService::register` → `ReferralService::attach` (`pending`, `users.referred_by`). Nothing credited. Later `phone_verified_at` goes null→set (phone-OTP login or change-number OTP) → `User::updated` hook → `rewardIfEligible` → referrer +`referral_reward` (withdrawable), new user +`referral_welcome`; both notified. Failures: unknown/self/disabled code ⇒ no row (silent); caps / banned referrer ⇒ `void` with reason (visible in admin); second verification ⇒ no-op; referred account deleted within 7 days ⇒ `void('deleted_early')` with clawback (`AccountDeletionService`).

**E.2 Buy coins — web.** Wallet → pack → method sheet (built from `methodsFor()`) → `POST pay.begin`.
- *Manual*: `pending` + instructions (JazzCash/EasyPaisa/bank text from settings) → user pays outside → `POST pay.proof` (method, txn id, screenshot on the `local` disk) → `review` → Wallet shows "Waiting for approval" → admin approves (E.7) → `paid → fulfilled` → coins (`payment:{id}`) + notification. Reject ⇒ "Not approved: {note}" + Try again (new payment). No proof within `manual_expire_hours` ⇒ `cancelled`.
- *Stripe*: `begin` creates the Checkout Session → redirect → pay → Stripe redirects to `pay.return` → "Confirming…" → the Wallet polls `pay.show` (which after 20 s also runs `StripeGateway::sync()`); the webhook (E.8) is the normal crediting path. Cancel ⇒ `cancelled`; card declined ⇒ Checkout handles retries; `session.expired` ⇒ `cancelled`.
- *PayPal*: only when the pack has `price_usd_minor` → `begin` creates the order → redirect to PayPal → back to `pay.return?token=ORDER` → server `capture()` (E.9) → `settle()` → redirect to Wallet with a toast.

**E.3 Buy coins / plan — Android.** Wallet/Premium list packs/plans that have a `play_product_id`; prices come from `NativeBilling.getProducts()` (Play's localised string), never the admin's PKR. Tap → `buyOnPlay({productId, accountHash})` → Play sheet → `PURCHASED` → `POST pay.play.verify {product_id, purchase_token, order_id}` → server verifies with Google (B.8) → credits/activates → 200 → JS `NativeBilling.consume({purchaseToken})`. `PENDING` (cash at a store) ⇒ 202, "Waiting for Google Play"; the plugin's `purchaseUpdated` event re-submits when it becomes PURCHASED. Network drop before verify / app killed ⇒ on next app open `recoverPurchases()` → `queryPurchases` → re-posts every unconsumed token → 200 idempotent → consume. Google 5xx ⇒ 503, the app shows "Try again" and does **not** consume. Web methods from the app UA ⇒ 422 (never offered anyway). Play from a browser ⇒ 422.

**E.4 Buy plan — web.** Same as E.2 with `purpose=plan`; `fulfil()` → `PlanService::activate()`: new/extended/queued sub with a benefits snapshot, `users.plan_*`, badge (computed) and ads-off apply on the next request, period-0 coins. Renew (button from 7 days before `ends_at`) ⇒ new payment ⇒ extends from `ends_at`. Expired ⇒ new period from now. A different plan while active ⇒ queued, UI "starts on {date}".

**E.5 Promote (each kind).** Promote → step 1 target → step 2 card (prefilled, editable title/text/CTA; card: image + link; link: URL, title/image prefilled by `LinkPreviewService::preview()`) → step 3 budget (presets 100/250/500/1000 + free input, live "≈ N views · R coins per 1,000 views", balance; not enough ⇒ primary button becomes **Get coins** → Wallet; the draft is kept in `sessionStorage`) → step 4 review → `POST promotions.store` with `client_token`. Prefill per kind: `status` → title "Status by {name}", body = status text, image = `statuses.media?variant=thumbnail` (photo/video) or a background swatch, `ends_at = expires_at`, `target_url = route('promotions.go')`, wizard warning tick "Promoted status updates are shown to people outside your contacts"; `channel` → name/description/avatar, `target_url = route('channels.link', ChannelService::link token)`; `community` → name/description/avatar, `target_url = route('communities.join.show', invite token — generated if missing)`; `business` → user name + category label/description, avatar, `target_url = route('promotions.go')`; `card` → user image/title/body/URL; `link` → URL. Auto-approve only for the four internal kinds when `promo_auto_approve`. Failures: insufficient coins ⇒ 422 + Get coins (no row); not owner / target gone ⇒ 422; over `promo_max_active` ⇒ 422 "You already have 5 promotions running"; frozen wallet ⇒ 422. Refunds: reject ⇒ full; user/admin stop or target vanished ⇒ unused share (ceil rule); finished ⇒ none.

**E.6 Admin review (promotions).** Queue oldest-first; card preview rendered by the same Blade partial as `admin/ads/edit` (`admin/partials/ad-card`); owner (link to person page), kind, target link, external URL (new tab), coins/budget; **Approve** / **Reject (note required, full refund)**; on running ones **Stop** with "refund unused" ticked by default (untick = policy breach, no refund). All audited with meta `{campaign, owner, coins}`.

**E.7 Manual payment approval.** Payments (default filter `review`) → detail: user, item, amount, method, typed txn id with "also used on payment #N" warning when it repeats, screenshot (streamed, audited), the person's previous payments and ledger tail → **Approve** (`settle(gateway_ref "manual:{id}")`) / **Reject (note)**. Later **Refund** (note; money returned outside the app) ⇒ `reverse(viaGateway:false)`: coins clawback (shortfall recorded and shown) or plan revoked ⇒ `refunded`. All three audited.

**E.8 Stripe webhook.** `WebhookController@stripe`: raw body + `Stripe-Signature` → `constructEvent` (bad signature ⇒ 400, logged, no row) → `recordEvent()` (`firstOrCreate`; a unique violation on a race ⇒ re-read) → `processEvent()`: `lockForUpdate` on the event row; `processed_at` set ⇒ 200 `duplicate`; otherwise run the handler (B.8), set `processed_at`, commit, 200. Any exception ⇒ `error` saved, `report()`, **500** so Stripe retries; the retry finds `processed_at` null and re-processes; `settle()`/`fulfil()` are idempotent (status guard + ledger key), so a crash after credit but before `processed_at` cannot credit twice. Amount/currency mismatch ⇒ `failed` + dashboard flag, event processed, 200 (no retry).

**E.9 PayPal capture.** `pay.return` (owner check) → `capture()` with `PayPal-Request-Id = uuid` → `COMPLETED` + amounts match ⇒ `recordEvent('paypal', "capture:{id}")` + `settle()`; `ORDER_ALREADY_CAPTURED` (our server crashed after capture last time, or the user refreshed) ⇒ `GET` order ⇒ same path; `INSTRUMENT_DECLINED` ⇒ redirect back to the approval link; `ORDER_NOT_APPROVED` ⇒ stays `pending` with a message; anything else ⇒ `failed`. Optional verified webhook is a second idempotent path for `CAPTURE.COMPLETED`/`REFUNDED`.

**E.10 Play verification.** B.8 step list. Replay (same user, same token) ⇒ 200 without a second credit; token from another account ⇒ 409 and log; `purchaseState 1` ⇒ 422; `consumptionState 1` without a payment ⇒ 422 `consumed` (admin can credit manually); Google down ⇒ 503, no consume. Voided later ⇒ `chat:play-sweep` ⇒ `reverse('play_void')`: coins clawed back (shortfall recorded) or plan revoked; the user is notified "Google refunded this purchase"; the payment shows "Reversed by Google" and the dashboard lists shortfalls.

**E.11 Subscription expiry.** `chat:expire-plans` hourly → `expire()`: `active` past `ends_at` ⇒ `expired`; benefits stop; plan badge disappears (computed); a coin/admin badge stays; queued rows start; notification "Your Pro plan ended — renew". `remindEnding()` sends "ends in 3 days" once (`reminded_at`).

**E.12 Monthly coins.** `chat:plan-coins` daily → `grantMonthlyCoins()`; keys `plan-coins:{sub}:{n}`; History note "Pro plan · month 3 coins". Run twice ⇒ one row. Missed days ⇒ catch-up loop.

**E.13 Badge purchase.** Premium (and Profile header "Get verified") → sheet "500 coins · 1 year" → `POST badge.buy {client_token}` → debit + `verified_until` → tick everywhere on the next payload. Insufficient ⇒ 422 + Get coins. Plan badge active ⇒ button still offered as "Extend verified badge" (coin period stacks after the plan). Refund path: admin `badge.removed` + `coins.adjusted` only.

**E.14 Refund/failure matrix.**

| situation | result | key |
|---|---|---|
| promotion rejected | full refund | `promo:{id}:refund` |
| promotion stopped (user/admin/target gone/owner deleted) | unused share, once | `promo:{id}:refund` |
| promotion finished | none | — |
| coins payment refunded (admin/Stripe/PayPal/Play void) | clawback to zero, shortfall recorded | `payment:{id}:refund` |
| plan payment refunded | sub `revoked`, users.plan_* cleared | — |
| manual pending without proof | `cancelled` by sweep | — |
| Stripe amount mismatch | `failed`, no credit, flagged | — |
| referral voided | clawback both sides | `referral:{id}:void:*` |
| fulfil exception after money taken | stays `paid`, admin Retry | — |

---

## F. Admin UI (Blade: `<x-layouts.admin>`, `.card.admin-tool`, `.admin-table`, `<x-admin.bars>`, `<x-admin.sparkline>`, `<x-admin.pager>`, `.badge-*`, `admin/partials/setting-field`)

Nav (`components/layouts/admin.blade.php`): new group **Money** between *Content* and *System*: Overview (`admin.money`, `wallet`), Plans (`admin.plans*`, `crown`), Coin packs (`admin.coin-packs*`, `coins`), Payments (`admin.payments*`, `receipt`, count = `status=review`), Promotions (`admin.promotions*`, `megaphone`, count = `review_status=pending`), Referrals (`admin.referrals*`, `gift`). *Ads* stays in System (house ads only).

- **Overview** (`admin/money/index.blade.php`): KPI cards like the dashboard — revenue 30 d per gateway & currency (fulfilled payments), coins sold 30 d, active subscriptions, coins in wallets (liability), pending manual reviews, pending promotion reviews, referrals rewarded 7 d; sparkline of daily revenue; **flags** list: amount mismatches, clawback shortfalls, `paid`-not-fulfilled payments, Play verify 409s (last 7 days, from `payments.meta`/logs table-free: query payments with `status=failed and meta.reason`); warning banner when `paid_enabled` is off or a gateway is enabled but unconfigured or the scheduler heartbeat is stale.
- **Plans** (`admin/plans/index|edit`): table name · period · price · benefits chips (No ads / Verified / +N coins / limits) · Play id · active subs · active toggle. Form: name, slug, description, period, price + currency, USD price, benefit checkboxes with the numeric fields revealed when ticked, monthly coins, four limit fields (blank = default), Play product id, active, sort. Delete refused while referenced.
- **Coin packs** (`admin/coin-packs/index`): inline table with an add row: name, coins, bonus, price, currency, USD price, Play id, active, sort.
- **Payments** (`admin/payments/index|show`): filters; columns #, person, item, gateway, amount, status badge, platform, created, reviewer. Show page: snapshot vs gateway-reported amount (flag on mismatch), refs, `payment_events` timeline, manual proof (image streamed via `payments.proof`), duplicate-txn-id warning, person's last payments + ledger tail, actions: Approve / Reject (note) / Refund (note; Play shows "refund in Play Console; the daily check reverses it" + **Reverse now**) / Retry (only when `paid`).
- **Promotions** (`admin/promotions/index|show`): tabs; rows: kind badge, owner, card thumb, coins, budget bar `impressions/view_budget`, taps, CTR, submitted. Show: rendered card (shared partial), target link, owner card, external URL, stats (reuse the `admin/ads/edit` performance block), Approve / Reject (note) / Stop (refund tick).
- **Subscriptions** (`admin/subscriptions/index`): person, plan, source, status, starts/ends, payment link.
- **Referrals** (`admin/referrals/index`): top referrers (rewarded, coins), suspicious groups (same `ip_hash` ≥ 3 in 7 days), void list with reasons, Void action (note).
- **Person page → Money tab** (`admin/users/tabs/money.blade.php`): wallet balance/withdrawable/frozen (Freeze/Unfreeze), active + queued plan with Grant (plan select + days) / End (note), badge state with Grant (days) / Remove, referrals summary (invited/rewarded/void reasons), Adjust coins form (± amount, note, hidden uuid `token`), ledger table (paginated), payments list, promotions list.
- **App settings** (`admin/settings.blade.php`): new `<section class="card admin-tool" id="paid">` inside the existing settings form with `<input type="hidden" name="paid_section" value="1">`: master switch, currency; **Promote** (on/off, six rates, min/max coins, max active, daily cap, weight, auto-approve, placements checkboxes, blocked hosts); **Refer & earn** (on/off, reward, welcome, daily cap, IP cap); **Verified badge** (coin price, days); **Withdrawals** (enable — future); **Manual payments** (on/off, JazzCash / EasyPaisa / Bank textareas, note, expiry hours, proof retention); **Stripe** (on/off, publishable key, secret key, webhook secret — `setting-field` with the Saved/Remove pattern — webhook URL shown read-only); **PayPal** (on/off, client id, secret, mode, webhook id, webhook URL); **Google Play** (on/off, package name, service-account JSON textarea (secret), min app code, RTDN not required); per-gateway **Test connection** buttons (`settings.pay-check`, like `turn.check`). `SystemController::updateSettings()` validates these under `if ($request->has('paid_section'))` via a private `paidRules()`; secrets go through `AppConfigService::update()`; audit `paid.settings_updated` with changed key names only.

Every admin action calls `AdminAuditService::record($admin, action, $target, description, meta)` with the model (Plan, CoinPack, Payment, AdCampaign, Subscription, Referral, User); descriptions never include secrets.

---

## G. User UI

**Settings rows** (`profile/edit.blade.php` `$sections`, right after *Business tools*; `ProfileController::edit` passes `'paid' => MonetisationService::settingsFlags($user)` and the rows are added only when the flag is true):

| key | title | text | icon |
|---|---|---|---|
| `premium` | Premium | "Plans, no ads, verified badge, bigger limits" | `crown` |
| `wallet` | Wallet | "Balance, buy coins, history" | `coins` |
| `promote` | Promote | "Boost your status, channel, business or link" | `megaphone` |
| `refer` | Refer & earn | "Invite friends, earn coins" | `gift` |

Sections `resources/views/profile/sections/premium|wallet|promote|refer.blade.php` render a root `[data-premium]`, `[data-wallet]`, `[data-promote]`, `[data-refer]` with a loading spinner; `resources/js/ui/settings.js` `initSettings()` lazy-imports `./premium`, `./wallet`, `./promote`, `./refer` when the root exists (exactly like `storage-manager`). Each module is an `html``/raw()` class (`Premium`, `Wallet`, `Promote`, `Refer`) that fetches its JSON route from `App.config.routes` and re-renders on `settings:section`. The settings header shows a coin chip "🪙 1,250" next to the avatar when `paid_enabled` (`data-wallet-chip`, refreshed by the Wallet module).

`AppConfigComposer` `'paid'` block: `{enabled, promote, referral, currency, platform: 'web'|'android', verified: bool, plan: {name, until} | null, play: {enabled, accountHash, minAppCode}, withdraw: bool}`. The client never decides the gateway list — it comes from `methodsFor()` in `wallet.show` / `premium.show`.

- **Premium**: current plan card ("Pro · until 12 Nov 2026" + benefits ticks + Renew from 7 days before; "Next: Plus starts on …" for queued) or plan cards with benefit ticks and prices (`paid_currency`; in the app: the Play price string); Choose → method sheet (web: Manual / Card (Stripe) / PayPal; Android: single "Continue with Google Play"); "Get verified" card (price/days, Buy / Extend / "Included in your plan"); links to Refund policy.
- **Wallet**: big **Balance**; tiles **Earned** (withdrawable; lifetime `earned_total` underneath) with disabled **Withdraw · Coming soon** (toast "Withdrawals are coming soon") unless `withdraw` true, and **Purchased**; pending payments banner (manual: "Waiting for review" / "Upload screenshot" form; Stripe/PayPal: "Confirming…" polling `pay.show`); **Buy coins** pack grid (bonus shown "+50"); **History** list (icon by type, note, ± amount, date, link to the promotion/payment).
- **Promote**: "My promotions" rows (thumb, status chip Under review / Running / Finished / Stopped / Not approved, budget bar, Views · Taps · CTR, tap → detail with 30-day bars, placements, Stop) + "New promotion" 4-step wizard (E.5); the card preview uses the same markup as `ads.js` (extract `adCardHtml(ad)` from `resources/js/chat/ads.js` and import it).
- **Refer & earn**: code, link, **Share** (`navigator.share` else copy; reuses `inviteMessage()` from `resources/js/chat/invite.js`), "You earn N coins when a friend joins and verifies their number", counters Invited · Verified · Coins earned, list (name, Pending/Rewarded, date).
- **In-app entry points** (chat page): own status viewer "⋯" → **Promote status** (`profile.edit?tab=promote&kind=status&id=…`); channel info (admin) → **Promote channel**; community info (admin) → **Promote community**; Business tools section → **Promote my business**; chat list overflow → Wallet. The wizard reads `kind`/`id` from the query and preselects.
- **Payment sheet**: built only from the server's `methods`; the Android bundle never renders web methods (they are absent from the payload); `play.minAppCode` above the installed build ⇒ "Update the app to buy" (reuses the X4 update prompt).

**Verified badge**: `UserResource` += `'verified' => app(BadgeService::class)->isVerified($this->resource)`; every hand-built user payload that has `'avatar_hue' =>` (`ChatCardService::for()`, `GroupService::payload()` members, `StatusService` people list, `ChannelService::payload()` admins, `ContactService`, `NewMessageNotification` sender) adds the same boolean (memoised per request). `templates.js` gets `nameWithBadge(user)` → `name + <span class="verified-badge" title="Verified">{icon('badge-check')}</span>` used in `conversationItem`, `searchResultItem`, `contactItem`, `chatHeaderUser`, contact-info, group member lists, status viewer header, message-info, people picker. Blade `<x-verified-badge :user="$user" />` on `profile/sections/profile.blade.php` ("Verified until …"), `profile-qr.page`, admin person strip. CSS in `components.css`, RTL-safe (`margin-inline-start`). Users cannot hide it; admins can remove coin/admin badges.

---

## H. Android (`mobile/`)

`mobile/android/app/build.gradle`: `implementation "com.android.billingclient:billing:7.1.1"` (`com.android.vending.BILLING` is merged by the library). New `mobile/android/app/src/main/java/com/hunario/chat/BillingPlugin.java` — `@CapacitorPlugin(name = "One2OneBilling")`, registered in `MainActivity.onCreate()` next to `registerPlugin(NativeAppPlugin.class)`; `NativeAppPlugin` unchanged.

| method | input | output / behaviour |
|---|---|---|
| `isAvailable()` | — | `{available, reason?}` — `BillingClient.newBuilder(...).enablePendingPurchases()`, `startConnection` with backoff, `isReady()` |
| `getProducts({ids})` | product ids from `wallet.show`/`premium.show` | `{products:[{productId, title, price, priceMicros, currency}]}` via `queryProductDetailsAsync(INAPP)` |
| `purchase({productId, accountHash})` | | `launchBillingFlow` with `setObfuscatedAccountId(accountHash)`; resolves `{purchaseToken, orderId, productId, state: 'purchased'|'pending'}` from `PurchasesUpdatedListener`; rejects `cancelled`, `item_already_owned` (⇒ JS calls `getPendingPurchases`), `unavailable`, `error` |
| `getPendingPurchases()` | — | `queryPurchasesAsync(INAPP)` → `{purchases:[{purchaseToken, orderId, productId, state}]}` (unconsumed) |
| `consume({purchaseToken})` | | `consumeAsync` → `{ok}` — called **only after** the server returned 200 |
| event `purchaseUpdated` | | `notifyListeners` when a PENDING purchase becomes PURCHASED, or a purchase completes outside the flow |

Implementation notes: one `BillingClient` per activity lifetime, reconnect on `onBillingServiceDisconnected`; resolve saved `PluginCall`s on the main thread; never credit from `Purchase.getPurchaseState()` — only the server does; single-thread executor like `NativeAppPlugin`.

JS bridge: `resources/js/native/plugins.js` += `export const NativeBilling = registerPlugin('One2OneBilling');`. New `resources/js/native/billing.js`:

```js
export async function playAvailable();                                   // false off-native
export async function playProducts(ids);                                 // [{productId, price, …}]
export async function buyOnPlay({ productId, accountHash, verifyUrl });  // purchase → POST verify → consume on 200; 202 = pending; 503/network = no consume; maps errors to user messages
export async function recoverPurchases({ verifyUrl });                   // getPendingPurchases → verify each → consume on 200
export function onPurchaseUpdated(handler);                              // re-verify when a pending purchase completes
```

`resources/js/native/app.js` `initNativeApp()`: when `appConfig.user && appConfig.paid?.play?.enabled` → `recoverPurchases()` once per open (crash recovery) and `onPurchaseUpdated(recoverPurchases)`. `ui/wallet.js` / `ui/premium.js` call `buyOnPlay` when `config.paid.platform === 'android'`. The web bundle never calls Play; the native bundle never renders manual/Stripe/PayPal.

---

## I. Test plan

**PHPUnit — `tests/Feature/Money/`** (`RefreshDatabase`, phone-numbered users as in `AdsTest`):

- `CoinLedgerTest`: credit/debit write ledger rows with `balance_after`; debit over balance throws and leaves the wallet unchanged; unsigned column rejects a forced underflow; same key twice ⇒ one row, one balance change; concurrent debits (two connections, `lockForUpdate`) never go negative; debit consumes purchased coins first; `refund()` restores the withdrawable share proportionally; `clawback()` stops at zero and records the shortfall; frozen wallet blocks debits, accepts credits; `adjust()` idempotent per token and audited.
- `PlanServiceTest`: activate snapshots benefits and sets `users.plan_*`; period-0 coins once; same-plan renewal extends from `ends_at`; different plan queues and starts when the old one expires; `chat:expire-plans` clears benefits, keeps a coin badge, drops the plan badge; `chat:plan-coins` twice ⇒ one row, catch-up after missed days, 12 grants for a yearly plan (`travel`); editing a plan leaves a running sub unchanged; `LimitService` returns plan limits and defaults; group over the creator's limit refused; upload limit raised.
- `AdsOffTest`: ads-off user gets `null` from `pickForUser` with live house ads and promotions; `ChatConfigComposer` ads disabled.
- `BadgeTest`: buy sets `verified_until`; same token twice ⇒ one charge; new token extends; lifetime when days 0; plan badge shows without coins and follows the sub; `UserResource.verified`; admin grant/remove audited; price 0 ⇒ 404.
- `ReferralTest`: `/r/{code}` stores the session and register attaches `pending`; register alone credits nothing; reward only on the null→set transition (phone login and change-number paths); re-saving `phone_verified_at` ⇒ no second reward; self-referral ignored; `referred_id` unique; IP cap and daily cap void with reasons; banned referrer void; welcome 0 ⇒ no referred credit; referrer coins withdrawable, welcome not; `referral_enabled=false` ⇒ no attach and 404; deletion within 7 days claws back.
- `PromotionsTest`: targets per user; quote math (`intdiv`); create inserts the campaign then debits (insufficient ⇒ 422 and **no row**); same `client_token` ⇒ same campaign; kind/target/budget/placements correct per kind; card/link never auto-approved; auto-approve for internal kinds; `promo_max_active`; cannot promote someone else's status/channel/business; already promoted ⇒ 422; approve ⇒ active + audit; reject ⇒ full refund + audit, double reject ⇒ one ledger row; stop ⇒ ceil-rule refund once; status expiry via `chat:expire-statuses` stops + refunds; channel/community/business delete stops; stats endpoint owner-only; frozen wallet ⇒ 422.
- `PromotionServingTest`: pending/rejected/stopped never picked; approved picked; owner never sees their own; impressions stop **exactly** at `view_budget` and the campaign is `completed` by the Nth `recordImpression` (loop N+1 times, also under the daily cap); concurrent last-view race counts once; a tap on the completed card still records a click; `ads.tap` returns `open` per kind and records the click; `promotions.go` renders `openTarget`; house ads unaffected (null budget, admin index hides promotions); promoted status viewable by a non-contact via `statuses.media`.
- `PaymentsManualTest`: begin (web only) ⇒ pending with snapshot; same `client_token` ⇒ same payment; proof stored on the `local` disk (not public) ⇒ `review`; approve credits / activates once (double approve no-op); reject stores the note, no credit; duplicate txn id allowed but flagged; refund claws back with shortfall + audit; sweep cancels stale pending; Android UA ⇒ 422 for manual/stripe/paypal; `paid_enabled=false` ⇒ 404; proof route admin-only and audited; proofs purged after `proof_keep_days`.
- `StripeWebhookTest` (SDK stubbed / signed test payloads): bad signature ⇒ 400 and no event row; valid `checkout.session.completed` ⇒ paid + fulfilled once; same event id twice ⇒ 200, one credit; different event id, same session ⇒ no second credit; amount mismatch ⇒ `failed`, no credit; handler exception ⇒ 500, `error` set, retry re-processes and credits once; `session.expired` ⇒ cancelled; `charge.refunded` ⇒ refunded + clawback; `sync()` fulfils from a server-side retrieve; CSRF exemption for `webhooks/*` asserted.
- `PayPalCaptureTest` (`Http::fake`): capture COMPLETED ⇒ fulfilled; wrong amount ⇒ failed; `ORDER_ALREADY_CAPTURED` ⇒ GET then fulfilled; capture twice ⇒ one credit; return with another user's payment ⇒ 403; item without USD price ⇒ PayPal absent and begin ⇒ 422.
- `PlayBillingTest` (`Http::fake` androidpublisher): valid token ⇒ payment + coins + acknowledge call; replay same user ⇒ 200, one row; token of another user ⇒ 409; `purchaseState 1` ⇒ 422; `consumptionState 1` without payment ⇒ 422; account-hash mismatch ⇒ 422; unknown product ⇒ 422; order id reuse ⇒ 422; Google 500 ⇒ 503 and no payment; plan product ⇒ subscription activated; PENDING ⇒ 202 then fulfilled on re-verify; web UA ⇒ 422; `chat:play-sweep` reverses once and notifies.
- `AccountDeletionMoneyTest`: payments anonymised (`user_id` null, amounts kept, proof deleted); wallet/ledger/referrals gone; promotions stopped; export includes wallet/payments/referrals/promotions.
- `PaidSwitchTest`: master off ⇒ settings rows hidden, every `paid` route 404, `ads.tap` still works for house ads, admin pages readable, `chat:expire-plans` still expires.

**PHPUnit — `tests/Feature/Admin/MoneyAdminTest.php`**: plans/packs CRUD + validation + audit rows; cannot delete a referenced plan; payments filters; approve/reject/refund/retry audited with notes; promotions queue + nav counts; person Money tab actions (adjust, freeze, grant/end plan, badge) audited; settings save the new keys, secrets encrypted and never rendered, `clear[]` works, audit line has no secrets; `pay-check` per gateway; non-admin ⇒ 403 on every `admin.money*` route.

**Vitest**

- `resources/js/ui/__tests__/wallet.test.js`: balance/earned/purchased; Withdraw disabled with "Coming soon"; packs; methods rendered only from the payload (`platform=android` ⇒ only Play, no manual/Stripe/PayPal in the DOM); manual flow renders instructions + proof form; polling after Stripe return; insufficient-coins error shows "Get coins".
- `ui/__tests__/premium.test.js`: plan cards with ticks; current/queued plan; Renew within 7 days; badge card states (buy / extend / included); min-app-code gate.
- `ui/__tests__/promote.test.js`: 4 steps; targets per kind; prefill; quote updates live (`intdiv` math); insufficient balance swaps the CTA and keeps the draft in `sessionStorage`; multipart for card; `client_token` sent once, button disabled; rows show chip, budget bar, views/taps; Stop confirms.
- `ui/__tests__/refer.test.js`: code + link; share uses `navigator.share` else copy; counters and list.
- `chat/__tests__/ads.test.js` (extend): "Promoted · name" tag; internal cards post `ads.tap` and open via the manager instead of navigating; external cards keep `target=_blank`; ads-off config renders nothing.
- `chat/__tests__/templates.test.js`: `nameWithBadge` adds the tick only when `verified`.
- `native/__tests__/billing.test.js`: `buyOnPlay` order purchase → verify → consume; verify 503/network ⇒ no consume; 202 ⇒ pending message, no consume; `recoverPurchases` re-verifies each pending token; user cancel ⇒ no request; off-native rejects cleanly.

---

## J. Risks & policy notes

- **Google Play Billing policy**: coins, plans and the badge are digital goods ⇒ inside the Android app only Play Billing is offered; the server refuses web gateways for the app UA and never links to web checkout; the app never mentions web prices (anti-steering) and shows Play's `ProductDetails` price. Play takes its fee — set Play prices in Play Console accordingly. Consume only after the server credited (unconsumed purchases are refunded by Google after 3 days) and recover on app open. Plans as consumable INAPP products are allowed; moving to auto-renewing Play subscriptions later means adding `subscriptionsv2` verification + RTDN — the `payments`/`subscriptions` shape already fits. A refund-policy page (`/refunds`) is linked from Wallet/Premium. Play's UGC rules apply to promotions ⇒ review queue (mandatory for card/link) and **Report** on promoted cards (reuse `users.report` against the owner with reason `spam`).
- **Play verification secret**: the service-account JSON is high value — encrypted at rest via `AppConfigService`, never rendered, scoped in Play Console to "View financial data / Manage orders" only; the stable `obfuscatedExternalAccountId` binds tokens to accounts; token hash unique; daily voided sweep; 5xx ⇒ no consume.
- **Stripe/PayPal in Pakistan**: no local merchant accounts (a foreign entity is needed) ⇒ manual JazzCash/EasyPaisa/bank is the realistic primary web method; both gateways stay hidden until keys exist. PayPal cannot charge PKR ⇒ USD price per item or hidden. Stripe PKR support depends on the account. Card data never touches the server (hosted pages), so no PCI scope.
- **Webhook correctness**: raw body must reach the signature check (`$request->getContent()`, route CSRF-exempt, no body-rewriting middleware); `payment_events.processed_at` gating; 500 on processing errors; all fulfilment through `settle()/fulfil()` under row locks with ledger keys, so replays never double-credit.
- **Manual-payment fraud**: fake screenshots, reused txn ids, wallet-provider reversals. Mitigations: private proof storage streamed only to admins (audited), duplicate-id flag, person history on the review page, refund path with clawback + shortfall, proof retention purge, `manual_expire_hours`, audit trail. A dual-approval threshold can be added later.
- **Referral abuse** (SIM farms): reward only after OTP verification, one reward per referred account, IP-hash and daily caps, banned/inactive referrer void, 7-day deletion clawback, wallet freeze, void reasons visible. Earned coins are only spendable in-app; when withdrawals arrive, KYC/AML rules apply — the withdrawable bucket is tracked from day one for that reason.
- **Promotion content**: user-generated ads ⇒ review, host blocklist + safe-URL checks, per-user daily cap, no self-views, report flow. A promoted status is shown to people outside the owner's contacts — the wizard warns and requires an explicit tick, and visibility is granted only while the promotion is approved/active/completed.
- **Ads-off fairness**: premium (ads-off) users see no promotions either; the quote text says so.
- **Data protection**: ledger, payments and referrals are personal data ⇒ `AccountExportService` includes wallet summary + ledger, payments (no proof images, no gateway payloads), referrals (counts + rewarded names/dates), promotions with stats; `AccountDeletionService` calls `ReferralService::voidForDeletedAccount()`, `PromotionService::stopAllFor()`, `PaymentService::anonymiseFor()` (proof files deleted, `user_id` nulls via `nullOnDelete`, amounts kept for accounting), and wallets/ledger/referrals cascade. IP stored only as a keyed hash. Update the privacy policy page (payments, referral data) and the Play Data safety form ("Financial info — purchase history").
- **Money accuracy**: integer minor units everywhere; snapshots on `payments` and `subscriptions`; view budget uses `intdiv`, refunds use `ceil` of used views — always in the platform's favour by at most one coin, stated in the Promote sheet; no proration when switching plans (queued instead).
- **Operational**: cron/`schedule:work` must run (`chat:doctor` heartbeat + the new Paid features section) for expiry, monthly coins, sweeps and Play voids; webhooks need public HTTPS; Stripe/PayPal/Play "Test connection" buttons verify keys before enabling; the overview flags stale heartbeat, unconfigured gateways, paid-not-fulfilled payments and shortfalls.