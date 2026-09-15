@php
    $field = fn (string $name, string $label, array $extra = []) => view('admin.partials.setting-field', ['name' => $name, 'label' => $label, 'values' => $values] + $extra)->render();
@endphp
<x-layouts.admin title="App settings" heading="App settings" subheading="Sign-up, SMS, email, GIFs and calls — saved in the database, no .env editing needed.">
    <nav class="admin-tabs" aria-label="Settings sections">
        <a href="#signup" class="admin-tab">Sign-up &amp; login</a>
        <a href="#sms" class="admin-tab">SMS</a>
        <a href="#email" class="admin-tab">Email</a>
        <a href="#extras" class="admin-tab">GIFs, calls &amp; invite</a>
        <a href="#legal" class="admin-tab">Legal pages</a>
        <a href="#android" class="admin-tab">Android app</a>
        <a href="#tests" class="admin-tab">Test</a>
    </nav>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="admin-stack admin-settings" data-loading-form novalidate>
        @csrf
        @method('PUT')

        {{-- Sign-up and login --}}
        <section class="card admin-tool" id="signup">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="user-plus" /> Sign-up &amp; login</h3>
                <label class="admin-setting-row">
                    <span>
                        <strong>Allow new accounts</strong>
                        <small>When off, the sign-up page is closed and "Create one" is hidden on the login page. People who already have an account can still sign in.</small>
                    </span>
                    <span class="switch">
                        <input type="hidden" name="registration_open" value="0">
                        <input type="checkbox" name="registration_open" value="1" @checked($registrationOpen) aria-label="Allow new accounts">
                        <span class="switch-track"></span>
                    </span>
                </label>
                <div class="admin-settings-grid">
                    {!! $field('signup_email', 'Email at sign-up', ['type' => 'select', 'options' => ['optional' => 'Optional (recommended)', 'required' => 'Required', 'hidden' => "Don't ask"], 'hint' => 'Without an email, people reset their password by logging in with their phone number (needs SMS).']) !!}
                    {!! $field('default_country_code', 'Default country code', ['placeholder' => '+92', 'hint' => 'Numbers typed as 0300 1234567 get this code. Leave empty to ask for the full number.']) !!}
                </div>
            </div>
        </section>

        {{-- SMS --}}
        <section class="card admin-tool" id="sms">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="message-square" /> SMS
                    @if ($smsReady)<span class="badge badge-success">Working</span>@else<span class="badge badge-muted">Off</span>@endif
                </h3>
                <p class="admin-muted">Used for "Log in with phone number" and "Change number". Each SMS is charged by your provider.</p>
                <div class="admin-settings-grid mt-3">
                    {!! $field('sms_driver', 'Provider', ['type' => 'select', 'options' => ['' => 'Use .env (SMS_DRIVER)', 'twilio' => 'Twilio', 'http' => 'Other SMS gateway (web API)', 'log' => 'Log only (testing, not in production)'], 'env' => 'SMS_DRIVER']) !!}
                </div>

                <details class="admin-disclosure admin-settings-sub" @if (($values['sms_driver'] ?? null) === 'twilio') open @endif>
                    <summary class="admin-section-title"><x-icon name="settings" /> Twilio <x-icon name="chevron-down" class="admin-disclosure-chevron" /></summary>
                    <div class="admin-settings-grid">
                        {!! $field('sms_twilio_sid', 'Account SID', ['placeholder' => 'AC…', 'env' => 'TWILIO_ACCOUNT_SID']) !!}
                        {!! $field('sms_twilio_token', 'Auth token', ['env' => 'TWILIO_AUTH_TOKEN']) !!}
                        {!! $field('sms_twilio_from', 'Sender number or Messaging Service SID', ['placeholder' => '+1… or MG…', 'env' => 'TWILIO_FROM']) !!}
                    </div>
                </details>

                <details class="admin-disclosure admin-settings-sub" @if (($values['sms_driver'] ?? null) === 'http') open @endif>
                    <summary class="admin-section-title"><x-icon name="settings" /> Other SMS gateway (web API) <x-icon name="chevron-down" class="admin-disclosure-chevron" /></summary>
                    <p class="admin-muted">Most Pakistani and international gateways work: fill in what your provider's API page says.</p>
                    <div class="admin-settings-grid">
                        {!! $field('sms_http_url', 'API URL', ['type' => 'url', 'placeholder' => 'https://api.provider.com/send', 'env' => 'SMS_HTTP_URL']) !!}
                        {!! $field('sms_http_method', 'Method', ['type' => 'select', 'options' => ['' => 'POST (default)', 'post' => 'POST', 'get' => 'GET']]) !!}
                        {!! $field('sms_http_format', 'Send as', ['type' => 'select', 'options' => ['' => 'Form (default)', 'form' => 'Form', 'json' => 'JSON', 'query' => 'In the URL']]) !!}
                        {!! $field('sms_http_phone_format', 'Number format', ['type' => 'select', 'options' => ['' => '+923001234567 (default)', 'plus' => '+923001234567', 'digits' => '923001234567']]) !!}
                        {!! $field('sms_http_to_field', 'Name of the number field', ['placeholder' => 'to']) !!}
                        {!! $field('sms_http_message_field', 'Name of the text field', ['placeholder' => 'message']) !!}
                        {!! $field('sms_http_params', 'Extra fields', ['type' => 'textarea', 'placeholder' => 'api_key=abc123&sender=MyApp', 'hint' => 'Written like api_key=abc&sender=MyApp. Stored encrypted.']) !!}
                        {!! $field('sms_http_headers', 'Extra headers', ['type' => 'textarea', 'placeholder' => 'X-Api-Key=abc123', 'hint' => 'Written like X-Api-Key=abc. Stored encrypted.']) !!}
                        {!! $field('sms_http_bearer', 'Bearer token', ['hint' => 'Sent as "Authorization: Bearer …".']) !!}
                        {!! $field('sms_http_success_text', 'Reply must contain', ['placeholder' => 'success', 'hint' => 'Optional: a word the gateway returns when the SMS was accepted.']) !!}
                    </div>
                </details>
            </div>
        </section>

        {{-- Email --}}
        <section class="card admin-tool" id="email">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="mail" /> Email
                    <span class="badge {{ $mailer === 'smtp' ? 'badge-success' : 'badge-muted' }}">{{ $mailer === 'smtp' ? 'SMTP' : ucfirst($mailer ?: 'off') }}</span>
                </h3>
                <p class="admin-muted">Used for password reset links, "Forgot PIN?" and "your email was changed" messages. Your hosting or Gmail/Zoho/Brevo give these SMTP details.</p>
                <div class="admin-settings-grid mt-3">
                    {!! $field('mail_mailer', 'Send emails with', ['type' => 'select', 'options' => ['' => 'Use .env (MAIL_MAILER)', 'smtp' => 'SMTP server', 'log' => 'Log only (testing)'], 'env' => 'MAIL_MAILER']) !!}
                    {!! $field('mail_host', 'SMTP host', ['placeholder' => 'smtp.gmail.com', 'env' => 'MAIL_HOST']) !!}
                    {!! $field('mail_port', 'Port', ['type' => 'number', 'placeholder' => '587', 'env' => 'MAIL_PORT']) !!}
                    {!! $field('mail_encryption', 'Security', ['type' => 'select', 'options' => ['' => 'Use .env', 'tls' => 'TLS / STARTTLS (port 587)', 'ssl' => 'SSL (port 465)', 'none' => 'None']]) !!}
                    {!! $field('mail_username', 'Username', ['placeholder' => 'you@example.com', 'env' => 'MAIL_USERNAME']) !!}
                    {!! $field('mail_password', 'Password', ['hint' => 'For Gmail use an "app password". Stored encrypted.', 'env' => 'MAIL_PASSWORD']) !!}
                    {!! $field('mail_from_address', 'From address', ['type' => 'email', 'placeholder' => 'no-reply@yourdomain.com', 'env' => 'MAIL_FROM_ADDRESS']) !!}
                    {!! $field('mail_from_name', 'From name', ['placeholder' => config('app.name'), 'env' => 'MAIL_FROM_NAME']) !!}
                </div>
            </div>
        </section>

        {{-- GIFs, calls, invite --}}
        <section class="card admin-tool" id="extras">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="sliders-horizontal" /> GIFs, calls &amp; invite link</h3>
                <div class="admin-settings-grid">
                    {!! $field('tenor_key', 'Tenor API key (GIF search)', ['hint' => 'Free from Google Cloud. Without it, GIF search is hidden.', 'env' => 'CHAT_TENOR_KEY']) !!}
                    {!! $field('invite_url', 'Invite link', ['type' => 'url', 'placeholder' => 'https://play.google.com/store/apps/…', 'hint' => 'Sent to friends who are not on the app yet. Empty = the sign-up page.', 'env' => 'CHAT_INVITE_URL']) !!}
                </div>
                <h4 class="admin-subtitle-row">Call relay (TURN) — makes calls work on mobile data</h4>
                <div class="admin-settings-grid">
                    {!! $field('turn_urls', 'TURN addresses', ['type' => 'textarea', 'placeholder' => 'turn:chat.example.com:3478?transport=udp,turns:chat.example.com:5349?transport=tcp', 'env' => 'CHAT_CALL_TURN_URLS']) !!}
                    {!! $field('turn_secret', 'Shared secret (coturn)', ['hint' => 'Or fill in a fixed username and password below.', 'env' => 'CHAT_CALL_TURN_SECRET']) !!}
                    {!! $field('turn_username', 'Username', ['env' => 'CHAT_CALL_TURN_USERNAME']) !!}
                    {!! $field('turn_password', 'Password', ['env' => 'CHAT_CALL_TURN_PASSWORD']) !!}
                </div>
            </div>
        </section>

        {{-- Legal pages (X5) --}}
        <section class="card admin-tool" id="legal">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="scale" /> Legal pages</h3>
                <p class="admin-muted">Shown on the <a class="admin-link" href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">privacy policy</a>, <a class="admin-link" href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">terms</a>, <a class="admin-link" href="{{ route('legal', 'child-safety') }}" target="_blank" rel="noopener">child safety</a> and <a class="admin-link" href="{{ route('legal', 'delete-account') }}" target="_blank" rel="noopener">delete account</a> pages. Google Play asks for these links.</p>
                <div class="admin-settings-grid mt-3">
                    <div class="form-group">
                        <label for="legal-owner" class="form-label">Operator (person or company)</label>
                        <input id="legal-owner" name="legal_owner" class="form-control @error('legal_owner') is-invalid @enderror" maxlength="120" value="{{ old('legal_owner', $legal['owner']) }}" placeholder="{{ config('app.name') }}">
                        @error('legal_owner')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                    <div class="form-group">
                        <label for="legal-email" class="form-label">Contact and child safety email</label>
                        <input id="legal-email" type="email" name="legal_email" class="form-control @error('legal_email') is-invalid @enderror" maxlength="191" value="{{ old('legal_email', $legal['email']) }}" placeholder="{{ config('mail.from.address') }}">
                        @error('legal_email')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                    <div class="form-group">
                        <label for="legal-country" class="form-label">Country</label>
                        <input id="legal-country" name="legal_country" class="form-control" maxlength="80" value="{{ old('legal_country', $legal['country']) }}" placeholder="Pakistan">
                    </div>
                    <div class="form-group">
                        <label for="legal-updated" class="form-label">"Last updated" date</label>
                        <input id="legal-updated" name="legal_updated" class="form-control" maxlength="40" value="{{ old('legal_updated', $legal['updated']) }}" placeholder="15 September 2026">
                    </div>
                </div>
            </div>
        </section>

        {{-- Notice --}}
        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="megaphone" /> Notice for everyone</h3>
                <p class="admin-muted">Shown at the top of the chats for every user, e.g. planned maintenance. Leave empty to hide it.</p>
                <div class="form-group mt-3">
                    <label for="app-notice" class="form-label">Notice</label>
                    <textarea id="app-notice" name="notice" class="form-control @error('notice') is-invalid @enderror" rows="3" maxlength="300" placeholder="e.g. The app will be updated tonight at 2 AM for about 10 minutes.">{{ old('notice', $notice) }}</textarea>
                    @error('notice')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <div class="admin-settings-save"><button type="submit" class="btn btn-primary btn-lg"><x-icon name="check" /> Save settings</button></div>
        <p class="admin-muted">Passwords and keys are encrypted with the app key and never shown again. The database, APP_KEY, APP_URL and Reverb still come from .env.</p>
    </form>

    {{-- Android app releases (X4) --}}
    <section class="card admin-tool" id="android">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="smartphone" /> Android app</h3>
            <p class="admin-muted">When you publish a newer version, the app shows "Update available" with what's new. Phones older than the oldest allowed version can't continue until they update.</p>
            <form method="POST" action="{{ route('admin.app-release.update') }}" enctype="multipart/form-data" class="admin-form mt-3" data-loading-form novalidate>
                @csrf
                @method('PUT')
                <div class="admin-settings-grid">
                    <div class="form-group">
                        <label for="release-code" class="form-label">Latest version code</label>
                        <input id="release-code" type="number" name="latest_code" min="1" class="form-control @error('latest_code', 'release') is-invalid @enderror" value="{{ old('latest_code', $android['latest_code']) }}" placeholder="e.g. 3">
                        <p class="form-hint"><code>versionCode</code> in <code>mobile/android/app/build.gradle</code>. Empty = no update prompt.</p>
                        @error('latest_code', 'release')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                    <div class="form-group">
                        <label for="release-name" class="form-label">Version name</label>
                        <input id="release-name" type="text" name="latest_name" maxlength="20" class="form-control @error('latest_name', 'release') is-invalid @enderror" value="{{ old('latest_name', $android['latest_name']) }}" placeholder="e.g. 1.2">
                        @error('latest_name', 'release')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                    <div class="form-group">
                        <label for="release-min" class="form-label">Oldest allowed version code <span class="optional">(optional)</span></label>
                        <input id="release-min" type="number" name="min_code" min="1" class="form-control @error('min_code', 'release') is-invalid @enderror" value="{{ old('min_code', $android['min_code']) }}" placeholder="e.g. 2">
                        <p class="form-hint">Older phones must update. Use it only when old versions stop working.</p>
                        @error('min_code', 'release')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                    <div class="form-group">
                        <label for="release-url" class="form-label">Store link <span class="optional">(optional)</span></label>
                        <input id="release-url" type="url" name="download_url" maxlength="500" class="form-control @error('download_url', 'release') is-invalid @enderror" value="{{ old('download_url', $android['download_url']) }}" placeholder="https://play.google.com/store/apps/details?id=…">
                        <p class="form-hint">Used instead of the APK below once the app is on Google Play.</p>
                        @error('download_url', 'release')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                    </div>
                </div>
                <div class="form-group">
                    <label for="release-notes" class="form-label">What's new <span class="optional">(optional)</span></label>
                    <textarea id="release-notes" name="notes" rows="3" maxlength="1000" class="form-control" placeholder="e.g. Your own notification tone for each chat, faster photos.">{{ old('notes', $android['notes']) }}</textarea>
                </div>
                <div class="form-group">
                    <label for="release-apk" class="form-label">APK file <span class="optional">(optional)</span></label>
                    <input id="release-apk" type="file" name="apk" accept=".apk,application/vnd.android.package-archive" class="form-control @error('apk', 'release') is-invalid @enderror">
                    @if ($android['apk_url'])
                        <p class="form-hint">Uploaded: <a class="admin-link" href="{{ $android['apk_url'] }}">{{ $android['apk_url'] }}</a> ({{ number_format(($android['apk_size'] ?? 0) / 1048576, 1) }} MB). Share this link so people can install the app.</p>
                        <label class="checkbox mt-2"><input type="checkbox" name="remove_apk" value="1"> Remove the uploaded APK</label>
                    @else
                        <p class="form-hint">Build it with <code>gradlew assembleRelease</code> (or the debug APK for testing). The link will be <code>{{ route('app.download.android') }}</code>.</p>
                    @endif
                    @error('apk', 'release')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                </div>
                <div><button type="submit" class="btn btn-primary"><x-icon name="check" /> Save Android app</button></div>
            </form>
        </div>
    </section>

    {{-- Tests --}}
    <section class="card admin-tool" id="tests">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="send-horizontal" /> Test your settings</h3>
            <p class="admin-muted">Save first, then send yourself a test.</p>
            <div class="admin-settings-grid mt-3">
                <form method="POST" action="{{ route('admin.settings.test-sms') }}" class="admin-test-form" data-loading-form novalidate>
                    @csrf
                    <label for="test-phone" class="form-label">Send a test SMS to</label>
                    <div class="admin-test-row">
                        <input id="test-phone" name="test_phone" type="tel" class="form-control @error('test_phone', 'testSms') is-invalid @enderror" placeholder="0300 1234567" value="{{ old('test_phone') }}">
                        <button type="submit" class="btn btn-secondary"><x-icon name="message-square" /> Send</button>
                    </div>
                    @error('test_phone', 'testSms')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                </form>
                <form method="POST" action="{{ route('admin.settings.test-mail') }}" class="admin-test-form" data-loading-form novalidate>
                    @csrf
                    <label for="test-email" class="form-label">Send a test email to</label>
                    <div class="admin-test-row">
                        <input id="test-email" name="test_email" type="email" class="form-control @error('test_email', 'testMail') is-invalid @enderror" placeholder="you@example.com" value="{{ old('test_email', auth()->user()->email) }}">
                        <button type="submit" class="btn btn-secondary"><x-icon name="mail" /> Send</button>
                    </div>
                    @error('test_email', 'testMail')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                </form>
            </div>
        </div>
    </section>
</x-layouts.admin>
