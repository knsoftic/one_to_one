{{-- App settings → Paid features (Y2). Included by admin/settings.blade.php inside the one settings form; `$field` and `$values` come from there. --}}
@php
    $money = app(\App\Services\MonetisationService::class);
    $switch = fn (string $name, string $title, string $text, bool $on) => '
        <label class="admin-setting-row">
            <span><strong>'.e($title).'</strong><small>'.e($text).'</small></span>
            <span class="switch">
                <input type="hidden" name="'.e($name).'" value="0">
                <input type="checkbox" name="'.e($name).'" value="1" '.($on ? 'checked' : '').' aria-label="'.e($title).'">
                <span class="switch-track"></span>
            </span>
        </label>';
    $number = function (string $name, string $label, $value, string $hint = '', int $min = 0, int $max = 10000000) use ($errors) {
        $id = 'paid-'.str_replace('_', '-', $name);
        $error = $errors->first($name);
        return '<div class="form-group"><label for="'.$id.'" class="form-label">'.e($label).'</label>'
            .'<input id="'.$id.'" type="number" name="'.e($name).'" min="'.$min.'" max="'.$max.'" class="form-control'.($error ? ' is-invalid' : '').'" value="'.e(old($name, $value)).'">'
            .($error ? '<p class="form-error">'.e($error).'</p>' : ($hint ? '<p class="form-hint">'.e($hint).'</p>' : ''))
            .'</div>';
    };
    $textarea = function (string $name, string $label, $value, string $hint = '', int $rows = 2) use ($errors) {
        $id = 'paid-'.str_replace('_', '-', $name);
        $error = $errors->first($name);
        return '<div class="form-group"><label for="'.$id.'" class="form-label">'.e($label).'</label>'
            .'<textarea id="'.$id.'" name="'.e($name).'" rows="'.$rows.'" class="form-control'.($error ? ' is-invalid' : '').'">'.e(old($name, $value)).'</textarea>'
            .($error ? '<p class="form-error">'.e($error).'</p>' : ($hint ? '<p class="form-hint">'.e($hint).'</p>' : ''))
            .'</div>';
    };
    $webhook = fn (string $route) => '<p class="form-hint admin-webhook-url">Webhook URL: <code data-copy>'.e(route($route)).'</code></p>';
@endphp
<section class="card admin-tool" id="paid">
    <div class="card-body">
        <h3 class="admin-section-title"><x-icon name="coins" /> Paid features</h3>
        <input type="hidden" name="paid_section" value="1">

        {!! $switch('paid_enabled', 'Turn on paid features', 'The master switch for plans, coins, promotions and refer & earn. Off by default. Balances and subscriptions are kept while it is off.', (bool) $paid['paid_enabled']) !!}

        <div class="admin-settings-grid mt-2">
            <div class="form-group">
                <label for="paid-currency" class="form-label">Currency</label>
                <select id="paid-currency" name="paid_currency" class="form-control">
                    @foreach (\App\Services\MonetisationService::CURRENCIES as $code => $symbol)
                        <option value="{{ $code }}" @selected(old('paid_currency', $paid['paid_currency']) === $code)>{{ $code }} ({{ $symbol }})</option>
                    @endforeach
                </select>
                <p class="form-hint">Used for new plans and coin packs, and for the manual payment instructions. PayPal needs a US dollar price on each item.</p>
            </div>
        </div>

        <p class="admin-backup-note"><x-icon name="shield-check" /> <span>Coins, plans and the badge are digital goods: inside the Android app only <strong>Google Play Billing</strong> is offered (Play policy); on the website people pay by manual transfer, card or PayPal. The server refuses the wrong method for the platform. Keep the <a class="admin-link" href="{{ route('legal', 'refunds') }}" target="_blank" rel="noopener">refund policy</a> page up to date.</span></p>

        <h4 class="admin-subtitle-row">Promote <span class="optional">(coins per 1,000 views)</span></h4>
        {!! $switch('promote_enabled', 'Let people promote', 'Their status, channel, community, business, a custom card or a link — shown as a promoted card in the app.', (bool) $paid['promote_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $number('promo_rate_status', 'Status', $paid['promo_rate_status'], '', 1, 100000) !!}
            {!! $number('promo_rate_channel', 'Channel', $paid['promo_rate_channel'], '', 1, 100000) !!}
            {!! $number('promo_rate_community', 'Community', $paid['promo_rate_community'], '', 1, 100000) !!}
            {!! $number('promo_rate_business', 'Business profile', $paid['promo_rate_business'], '', 1, 100000) !!}
            {!! $number('promo_rate_card', 'Custom card', $paid['promo_rate_card'], '', 1, 100000) !!}
            {!! $number('promo_rate_link', 'Website link', $paid['promo_rate_link'], '', 1, 100000) !!}
        </div>
        <div class="admin-settings-grid mt-2">
            {!! $number('promo_min_coins', 'Minimum coins per promotion', $paid['promo_min_coins'], '', 1, 1000000) !!}
            {!! $number('promo_max_coins', 'Maximum coins per promotion', $paid['promo_max_coins'], '', 1, 10000000) !!}
            {!! $number('promo_max_active', 'Max running per person', $paid['promo_max_active'], 'Pending and running promotions together.', 1, 100) !!}
            {!! $number('promo_daily_cap', 'Shows per person per day', $paid['promo_daily_cap'], 'How often one viewer sees the same promotion in a day.', 1, 50) !!}
            {!! $number('promo_weight', 'Priority vs. house ads', $paid['promo_weight'], 'Higher shows promotions more often than your own ads (1–100).', 1, 100) !!}
        </div>
        {!! $switch('promo_auto_approve', 'Skip review for status, channel, community and business', 'Custom cards and website links always wait for your approval.', (bool) $paid['promo_auto_approve']) !!}
        <div class="form-group mt-2">
            <span class="form-label">Placements promotions may use</span>
            <div class="admin-chips is-wrap">
                @foreach (\App\Support\AdPlacement::ALL as $key => $placement)
                    <label class="admin-chip is-check"><input type="checkbox" name="promo_placements[]" value="{{ $key }}" @checked(in_array($key, old('promo_placements', $paid['promo_placements']), true))><span>{{ $placement['label'] }}</span></label>
                @endforeach
            </div>
            <p class="form-hint">Also needs the placement switched on under Ads.</p>
        </div>
        {!! $textarea('promo_blocked_hosts', 'Blocked websites', $paid['promo_blocked_hosts'], 'One host per line (e.g. example.com). Custom cards and links to these are refused.') !!}

        <h4 class="admin-subtitle-row">Refer &amp; earn</h4>
        {!! $switch('referral_enabled', 'Let people invite friends for coins', 'Paid when the invited person verifies their mobile number.', (bool) $paid['referral_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $number('referral_reward', 'Coins for the inviter', $paid['referral_reward'], 'Counted as earned (withdrawable later).', 0, 100000) !!}
            {!! $number('referral_welcome', 'Coins for the new person', $paid['referral_welcome'], '0 = none.', 0, 100000) !!}
            {!! $number('referral_daily_cap', 'Rewards per inviter per day', $paid['referral_daily_cap'], '', 1, 10000) !!}
            {!! $number('referral_ip_cap', 'Rewards per connection per week', $paid['referral_ip_cap'], 'Sign-ups from one internet connection.', 1, 1000) !!}
        </div>

        <h4 class="admin-subtitle-row">Verified badge</h4>
        <div class="admin-settings-grid">
            {!! $number('badge_coin_price', 'Price in coins', $paid['badge_coin_price'], '0 = not sold for coins (plans can still include it).', 0, 10000000) !!}
            {!! $number('badge_days', 'Days it lasts', $paid['badge_days'], '0 = for life.', 0, 3650) !!}
        </div>

        <h4 class="admin-subtitle-row">Withdrawals</h4>
        {!! $switch('wallet_withdraw_enabled', 'Allow withdrawing earned coins', 'Off shows a "Coming soon" button. Turn on only once a payout process exists.', (bool) $paid['wallet_withdraw_enabled']) !!}

        <h4 class="admin-subtitle-row">Manual payments <span class="optional">(JazzCash, EasyPaisa, bank)</span></h4>
        {!! $switch('manual_enabled', 'Accept manual transfers on the website', 'The person pays outside the app, uploads a screenshot, and you approve it under Payments.', (bool) $paid['manual_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $textarea('manual_jazzcash', 'JazzCash instructions', $paid['manual_jazzcash'], 'Account name and number. Leave empty to hide this method.') !!}
            {!! $textarea('manual_easypaisa', 'EasyPaisa instructions', $paid['manual_easypaisa'], 'Leave empty to hide.') !!}
            {!! $textarea('manual_bank', 'Bank transfer instructions', $paid['manual_bank'], 'Bank, title, IBAN. Leave empty to hide.') !!}
            {!! $textarea('manual_note', 'Note shown under the details', $paid['manual_note'], 'e.g. "Approval takes up to 24 hours."') !!}
            {!! $number('manual_expire_hours', 'Cancel without a screenshot after (hours)', $paid['manual_expire_hours'], '', 1, 720) !!}
            {!! $number('proof_keep_days', 'Keep screenshots for (days)', $paid['proof_keep_days'], 'After the payment is settled.', 1, 3650) !!}
        </div>

        <h4 class="admin-subtitle-row">Stripe <span class="optional">(cards, website)</span></h4>
        {!! $switch('stripe_enabled', 'Accept cards through Stripe', 'Hosted checkout — card details never touch this server.', (bool) $paid['stripe_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $field('stripe_publishable_key', 'Publishable key', ['placeholder' => 'pk_live_…']) !!}
            {!! $field('stripe_secret_key', 'Secret key', ['placeholder' => 'sk_live_…']) !!}
            {!! $field('stripe_webhook_secret', 'Webhook signing secret', ['placeholder' => 'whsec_…', 'hint' => 'From the webhook endpoint in the Stripe dashboard.']) !!}
        </div>
        {!! $webhook('webhooks.stripe') !!}
        <button type="button" class="btn btn-secondary btn-sm mt-2" data-pay-check="stripe" data-url="{{ route('admin.settings.pay-check', 'stripe') }}"><x-icon name="plug-zap" /> Test Stripe connection</button>

        <h4 class="admin-subtitle-row">PayPal <span class="optional">(website)</span></h4>
        {!! $switch('paypal_enabled', 'Accept PayPal', 'Needs a US dollar price on each plan and pack — PayPal cannot charge in rupees.', (bool) $paid['paypal_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $field('paypal_client_id', 'Client ID') !!}
            {!! $field('paypal_secret', 'Secret') !!}
            {!! $field('paypal_mode', 'Mode', ['type' => 'select', 'options' => ['sandbox' => 'Sandbox (testing)', 'live' => 'Live']]) !!}
            {!! $field('paypal_webhook_id', 'Webhook ID', ['hint' => 'Optional: lets PayPal confirm payments and refunds on its own.']) !!}
        </div>
        {!! $webhook('webhooks.paypal') !!}
        <button type="button" class="btn btn-secondary btn-sm mt-2" data-pay-check="paypal" data-url="{{ route('admin.settings.pay-check', 'paypal') }}"><x-icon name="plug-zap" /> Test PayPal connection</button>

        <h4 class="admin-subtitle-row">Google Play Billing <span class="optional">(Android app)</span></h4>
        {!! $switch('play_enabled', 'Sell coins and plans through Google Play', 'Each plan and pack needs a Play product id (created in the Play Console). Purchases are verified with Google before coins are credited.', (bool) $paid['play_enabled']) !!}
        <div class="admin-settings-grid mt-2">
            {!! $field('play_package_name', 'Package name', ['placeholder' => 'com.hunario.chat']) !!}
            {!! $number('play_min_app_code', 'Minimum app build for purchases', $paid['play_min_app_code'], 'Older app builds are asked to update before buying. Leave empty for none.', 1, 1000000) !!}
        </div>
        {!! $field('play_service_account_json', 'Service account JSON', ['type' => 'textarea', 'hint' => 'A Google Cloud service account with "View financial data, orders" access to the app in the Play Console.']) !!}
        <button type="button" class="btn btn-secondary btn-sm mt-2" data-pay-check="play" data-url="{{ route('admin.settings.pay-check', 'play') }}"><x-icon name="plug-zap" /> Test Google Play connection</button>
        <p class="form-hint mt-2" data-pay-check-result hidden></p>
    </div>
</section>
