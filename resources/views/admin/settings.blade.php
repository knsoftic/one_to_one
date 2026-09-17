@php
    $field = fn (string $name, string $label, array $extra = []) => view('admin.partials.setting-field', ['name' => $name, 'label' => $label, 'values' => $values] + $extra)->render();
@endphp
<x-layouts.admin title="App settings" heading="App settings" subheading="Sign-up, SMS, email, GIFs and calls — saved in the database, no .env editing needed.">
    <nav class="admin-tabs" aria-label="Settings sections">
        <a href="#brand" class="admin-tab">App name &amp; icon</a>
        <a href="#signup" class="admin-tab">Sign-up &amp; login</a>
        <a href="#sms" class="admin-tab">SMS</a>
        <a href="#email" class="admin-tab">Email</a>
        <a href="#extras" class="admin-tab">GIFs, calls &amp; invite</a>
        <a href="#turn" class="admin-tab">Call server (TURN)</a>
        <a href="#legal" class="admin-tab">Legal pages</a>
        <a href="#android" class="admin-tab">Android app</a>
        <a href="#tests" class="admin-tab">Test</a>
    </nav>

    {{-- App name & icon --}}
    <section class="card admin-tool" id="brand">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="badge-info" /> App name &amp; icon</h3>
            <p class="admin-muted">Changes the name and icon everywhere in the app, on the sign-in page, in emails and notifications, and in the web app people install from the browser.</p>
            <form method="POST" action="{{ route('admin.brand.update') }}" enctype="multipart/form-data" class="admin-form admin-brand-form mt-3" data-loading-form data-brand-form novalidate>
                @csrf
                @method('PUT')
                <div class="admin-brand-layout">
                    <div class="admin-brand-fields">
                        <div class="form-group">
                            <label for="brand-name" class="form-label">App name</label>
                            <input id="brand-name" type="text" name="name" maxlength="{{ \App\Services\BrandService::NAME_MAX }}" required class="form-control @error('name', 'brand') is-invalid @enderror" value="{{ old('name', $brand['current_name']) }}" data-brand-name>
                            <p class="form-hint">About 12 letters fit under a phone's home-screen icon. The name from <code>.env</code> is "{{ $brand['default_name'] }}".</p>
                            @error('name', 'brand')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group">
                            <label for="brand-icon" class="form-label">App icon <span class="optional">(PNG, JPG or WebP)</span></label>
                            <input id="brand-icon" type="file" name="icon" accept="image/png,image/jpeg,image/webp" class="form-control @error('icon', 'brand') is-invalid @enderror" data-brand-icon>
                            <p class="form-hint">A square picture, 1024 × 1024 is best (at least 512 × 512). Keep the logo away from the edges: phones cut icons into circles and rounded squares.</p>
                            @error('icon', 'brand')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            @if ($brand['icon'])
                                <label class="checkbox mt-2"><input type="checkbox" name="remove_icon" value="1" data-brand-remove> Use the built-in icon again</label>
                            @endif
                        </div>
                        <div class="form-group">
                            <label for="brand-color" class="form-label">Icon background</label>
                            <span class="admin-brand-color">
                                <input id="brand-color" type="color" name="color" value="{{ old('color', $brand['color']) }}" data-brand-color>
                                <span class="form-hint">Fills the space around the logo where a phone shapes the icon.</span>
                            </span>
                            @error('color', 'brand')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                        </div>
                        @if ($brand['icon'] && ! $brand['gd'])
                            <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> PHP's <code>gd</code> extension is not installed, so the picture is used as it is and the phone app icon sizes can't be made. Enable it in aaPanel → PHP → Install extensions, then upload the icon again.</p>
                        @endif
                    </div>

                    <div class="admin-brand-preview" aria-label="Preview" data-brand-preview style="--brand-color: {{ $brand['color'] }}">
                        <span class="admin-brand-preview-title">Preview</span>
                        <div class="admin-brand-phone">
                            <span class="admin-brand-app">
                                <span class="admin-brand-tile is-square"><img src="{{ $brand['icon_url'] }}" alt="" data-brand-image></span>
                                <span class="admin-brand-label" data-brand-label>{{ $brand['current_name'] }}</span>
                            </span>
                            <span class="admin-brand-app">
                                <span class="admin-brand-tile is-circle"><img src="{{ $brand['icon'] ? $brand['maskable_url'] : $brand['icon_url'] }}" alt="" data-brand-image data-brand-padded></span>
                                <span class="admin-brand-label" data-brand-label>{{ $brand['current_name'] }}</span>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="admin-brand-phone-note">
                    <x-icon name="smartphone" />
                    <p>On Android phones the <strong>home-screen</strong> name and icon are part of the installed app, so they change with the next app version. After saving here, build the new version (it takes this name and icon from <code>{{ route('app.brand') }}</code>), then publish it in <a href="#android">Android app</a> below so phones get "Update available".</p>
                </div>
                <div><button type="submit" class="btn btn-primary"><x-icon name="check" /> Save name &amp; icon</button></div>
            </form>
        </div>
    </section>

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
                <p class="admin-muted">Your own server fills these in by itself: see <a href="#turn">Call server (TURN)</a>.</p>
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

    {{-- Call server (TURN) --}}
    @php
        $turnCheck = $turn['check'];
        [$turnBadge, $turnLabel] = match (true) {
            ! $turn['configured'] => ['badge-warning', 'Not set up'],
            $turnCheck === null => ['badge-muted', 'Not checked'],
            $turnCheck['ok'] => ['badge-success', 'Answers'],
            default => ['badge-danger', 'Problem'],
        };
        $turnResults = collect($turnCheck['results'] ?? [])->keyBy('url');
        // The script needs to know where the app is (quoted when the folder has spaces).
        $quote = fn (string $path) => preg_match('/^[\w.\/-]+$/', $path) ? $path : escapeshellarg($path);
        $turnSetupCommand = 'APP_DIR='.$quote(base_path()).' bash '.$quote(base_path('scripts/setup-turn.sh'));
        $transportLabels = ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS (port for strict networks)'];
    @endphp
    <section class="card admin-tool" id="turn">
        <div class="card-body">
            <div class="admin-turn-head">
                <h3 class="admin-section-title"><x-icon name="server" /> Call server (TURN)</h3>
                <span class="badge {{ $turnBadge }}">{{ $turnLabel }}</span>
            </div>
            <p class="admin-muted">Calls first try to connect the two phones directly. On mobile data and office or hotel Wi-Fi that often fails, and the call goes through a TURN server instead. With your own (coturn, free) calls connect everywhere and nothing is paid per minute.</p>

            @if ($turn['configured'])
                <div class="admin-table-wrap admin-turn-table mt-3">
                    <table class="admin-table">
                        <thead><tr><th>Address</th><th>Type</th><th>Last check</th></tr></thead>
                        <tbody>
                            @foreach ($turn['endpoints'] as $endpoint)
                                @php($result = $turnResults->get($endpoint['url']))
                                <tr>
                                    <td><code>{{ $endpoint['url'] }}</code></td>
                                    <td>{{ $transportLabels[$endpoint['transport']] }}</td>
                                    <td>
                                        @if (! $result)
                                            <span class="admin-muted">—</span>
                                        @elseif ($result['ok'])
                                            <span class="admin-turn-result is-ok"><x-icon name="circle-check" /> Answers{{ $result['relay'] ? ' · relay '.$result['relay'] : '' }}{{ $result['ms'] ? ' · '.$result['ms'].' ms' : '' }}</span>
                                        @else
                                            <span class="admin-turn-result is-bad"><x-icon name="circle-alert" /> {{ $result['detail'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="admin-muted mt-2">
                    {{ $turn['auth'] === 'secret' ? 'Shared secret: every person gets their own call password that expires after 12 hours.' : 'Fixed username and password.' }}
                    {{ $turn['from_admin'] ? '' : 'Read from .env.' }}
                    {{ $turnCheck ? 'Checked '.$turnCheck['at']->diffForHumans().'.' : '' }}
                </p>
                <p class="admin-muted">The server check finds a stopped coturn, a wrong secret or a certificate problem, but it runs on the server itself, so your hosting provider's firewall can't be seen from there. <strong>Test from this browser</strong> on a phone with Wi-Fi off confirms the ports are open.</p>
                <div class="admin-turn-actions">
                    <form method="POST" action="{{ route('admin.turn.check') }}" data-loading-form>
                        @csrf
                        <button type="submit" class="btn btn-primary"><x-icon name="refresh-cw" /> Check from the server</button>
                    </form>
                    <button type="button" class="btn btn-secondary" data-turn-browser-test data-url="{{ route('admin.turn.servers') }}"><x-icon name="monitor" /> Test from this browser</button>
                </div>
                <div class="admin-turn-browser" data-turn-browser-result aria-live="polite" hidden></div>
            @else
                <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> No TURN server yet: calls between people on mobile data or strict Wi-Fi may ring but never connect.</p>
            @endif

            <div class="admin-turn-setup">
                <h4 class="admin-subtitle-row">{{ $turn['configured'] ? 'Install it again or on a new server' : 'Set up your own TURN server' }}</h4>
                <ol class="admin-turn-steps">
                    <li>
                        <span>Open the server's terminal as <strong>root</strong> (SSH, or aaPanel → Terminal) and run:</span>
                        <span class="admin-turn-command"><code data-copy-source>{{ $turnSetupCommand }}</code><button type="button" class="btn btn-ghost btn-sm" data-copy><x-icon name="copy" /> Copy</button></span>
                        <span class="admin-muted">It installs coturn, makes a new shared secret, uses the site's SSL certificate, opens the ports and saves the address and secret here — nothing to type in.</span>
                    </li>
                    <li><span>In your hosting provider's firewall (VPS panel or security group), if it has one, allow <strong>TCP + UDP 3478</strong>, <strong>TCP + UDP 5349</strong> and <strong>UDP 49160–49400</strong>.</span></li>
                    <li><span>Come back here and press <strong>Check from the server</strong>. Then open this page on a phone with Wi-Fi off and press <strong>Test from this browser</strong>.</span></li>
                </ol>
                <p class="admin-muted">Using another TURN provider? Fill in its addresses and secret (or username and password) under <a href="#extras">GIFs, calls &amp; invite link</a>. Step-by-step: <a href="{{ route('admin.docs.show', 'deployment') }}#19-voice-video-calls-turn-server">server guide, step 19</a>.</p>
            </div>
        </div>
    </section>

    {{-- Android app releases (X4) --}}
    <section class="card admin-tool" id="android">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="smartphone" /> Android app</h3>
            <p class="admin-muted">When you publish a newer version, the app shows "Update available" with what's new. Phones older than the oldest allowed version can't continue until they update.</p>
            <p class="admin-muted"><x-icon name="store" class="icon-xs" /> Publishing on Google Play? Follow the <a href="{{ route('admin.docs.show', 'play-store') }}">Google Play release guide</a>.</p>
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
