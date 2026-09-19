# Paid features — admin guide

Plans, coins, promotions, refer & earn and the verified badge (Y2). Everything is off until you turn the master switch on, and everything is run from the admin panel. The technical design is in [MONETISATION-DESIGN.md](MONETISATION-DESIGN.md); Google Play setup is in [PLAY-BILLING.md](PLAY-BILLING.md).

## 1. Turn it on

**Admin → App settings → Paid features**

1. **Turn on paid features** — the master switch. Off means the Premium, Wallet, Promote and Refer & earn rows disappear from everyone's Settings and every purchase route answers 404; balances and subscriptions are kept.
2. **Currency** — used for new plans and coin packs and for the manual payment instructions (default PKR). PayPal cannot charge rupees, so every plan and pack also has an optional **US dollar price**; PayPal is hidden for items without one.
3. Pick at least one **payment method** (section 4 below). Until one is on and configured nobody can buy anything — `php artisan chat:doctor` warns about this.

## 2. Plans (Admin → Plans)

A plan has a period (monthly or yearly), a price, and the benefits you tick:

| Benefit | What it does |
|---|---|
| No ads | No house ads and no promotions for that person |
| Verified badge | The tick next to their name for as long as the plan runs |
| Free coins every month | Credited at activation and every month of the period (a yearly plan gets 12) |
| Bigger limits | Upload size, group members, broadcast recipients, storage — blank keeps the app default; a plan can only raise a limit |

Benefits are **copied onto the subscription at purchase** — editing a plan never changes what a running subscriber already has. A plan that has ever been bought cannot be deleted; deactivate it instead (running subscriptions continue).

Renewal of the same plan extends the end date; buying a different plan while one is running queues it to start when the current one ends (no proration, no lost days). Plans end by themselves (`chat:expire-plans`, hourly) and people get a "ends in 3 days" reminder.

**Person page → Money** lets you grant a plan for N days, end one (with a note), give or remove the badge, add or remove coins and freeze a wallet. Every action is in the audit log.

## 3. Coins and promotions

- **Coin packs** (Admin → Coin packs): coins (+ optional bonus) for a price. The Google Play product id is what the Android app buys.
- **Promote** (App settings → Paid features → Promote): people spend coins to promote their status, channel, community, business profile, a custom card or a website link. Each kind has its own rate in **coins per 1,000 views**. A promotion buys a view budget and runs through the same placements as your own ads; it stops exactly when the budget is used. Custom cards and links always wait for your approval; the other kinds too unless you tick *skip review*.
- **Promotions** (Admin → Promotions): the review queue. Reject (with a note) refunds all coins; Stop refunds the unused share (untick *refund* for a policy breach).
- **Refer & earn**: every account has a code and link (`/r/CODE`). The inviter earns coins only when the invited person **verifies their mobile number**, once per invited account; per-inviter daily and per-connection weekly caps and a 7-day deletion clawback limit abuse. Referral coins show as **Earned** in the wallet and are marked withdrawable; the Withdraw button says *Coming soon* until you turn withdrawals on (build a payout process first).
- **Verified badge**: sold for coins at the price you set (0 = not sold), for the number of days you set (0 = for life), and/or included in a plan.

## 4. Payment methods

| Method | Where it works | What you need |
|---|---|---|
| **Manual transfer** (JazzCash, EasyPaisa, bank) | Website | Write the account details in the settings. The person pays outside the app and uploads a screenshot; you approve or reject it under **Admin → Payments** (default filter: *To review*). Coins or the plan are delivered on approval. Screenshots are private and purged after the retention period. |
| **Stripe** (cards) | Website | Publishable key, secret key and the **webhook signing secret**. Add a webhook in the Stripe dashboard pointing at the URL shown in the settings (`/webhooks/stripe`) for `checkout.session.*` and `charge.refunded`. Payments are confirmed only by the webhook. |
| **PayPal** | Website | Client ID and secret (sandbox or live). Every item needs a US dollar price. Optional webhook id. |
| **Google Play Billing** | Android app | Play product ids on plans and packs, and a service-account JSON — see [PLAY-BILLING.md](PLAY-BILLING.md). |

**Google Play policy:** inside the Android app only Google Play is offered (coins, plans and the badge are digital goods); the server refuses web methods from the app and Play from the web. Prices in the app come from Google, never from the admin price. A refund policy page exists at `/refunds` — edit `resources/views/legal/refunds.blade.php` to match how you actually handle refunds.

Use the **Test connection** buttons before turning a provider on.

## 5. Day to day

- **Admin → Money** — revenue, coins sold, active plans, coins held in wallets, what is waiting for review, and flags: amount mismatches, refunds that could not take back all the coins, payments taken but not delivered (press **Retry**), stale scheduler.
- **Refunds**: a refunded payment takes the coins back (any shortfall is recorded) or ends the plan. Stripe/PayPal refunds can be sent from the payment page; Google Play refunds are made in the Play Console and picked up daily (`chat:play-sweep`).
- **Scheduler** must run (`php artisan schedule:run` every minute): plan expiry, monthly coins, stale-payment cleanup, promotion sweeps, Play voids.
- **Data**: a person's wallet, ledger, payments, referrals and promotions are in their data export; on account deletion payments are kept for accounting without the person's identity, the rest is removed.
