<x-layouts.guest title="Sign in" :wide="true" :scripts="['resources/js/auth/qr-login.js']">
    <div class="login-layout">
    <div class="login-form">
    <h2 class="auth-title">Welcome back 👋</h2>
    <p class="auth-subtitle">Sign in to continue your conversations.</p>

    <form method="POST" action="{{ route('login.attempt') }}" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        <x-field
            name="login"
            label="Email, username or mobile"
            icon="user-round"
            placeholder="you@example.com"
            autocomplete="username"
            autocapitalize="none"
            spellcheck="false"
            required
            autofocus
        />

        <x-field
            name="password"
            type="password"
            label="Password"
            icon="lock"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
        />

        <div class="auth-row">
            <label class="checkbox">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="auth-link">Forgot password?</a>
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <span>Sign in</span>
            <x-icon name="arrow-left" class="rotate-180" />
        </button>
    </form>

    {{-- Log in with the mobile number and an SMS code (A1) --}}
    @if (Route::has('login.phone') && app(\App\Services\SmsService::class)->available())
        <div class="auth-divider"><span>or</span></div>
        <a href="{{ route('login.phone') }}" class="btn btn-secondary btn-lg btn-block" data-phone-login-link>
            <x-icon name="smartphone" /> Log in with phone number
        </a>
    @endif

    <p class="auth-alt">
        Don't have an account?
        <a href="{{ route('register') }}" class="auth-link">Create one</a>
    </p>
    </div>

    {{-- Log in with your phone (P10) --}}
    <aside class="qr-login" data-qr-login data-create-url="{{ route('login.qr') }}" data-status-url="{{ route('login.qr.status', ['token' => '__TOKEN__']) }}">
        <h3 class="qr-login-title">Log in with your phone</h3>
        <ol class="qr-login-steps">
            <li>Open {{ config('app.name') }} on your phone</li>
            <li>Tap <strong>Menu ⋮</strong> → <strong>Linked devices</strong></li>
            <li>Tap <strong>Link a device</strong> and point it at this code</li>
        </ol>
        <div class="qr-login-box" data-qr-box aria-label="QR code to log in with your phone" role="img"><span class="spinner"></span></div>
        <p class="qr-login-code">Can't scan? Type <strong data-qr-code>—</strong></p>
    </aside>
    </div>
</x-layouts.guest>
