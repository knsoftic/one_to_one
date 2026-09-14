<x-layouts.guest title="Log in with phone number">
    <div class="two-step-head">
        <span class="two-step-icon"><x-icon name="smartphone" /></span>
        <h2 class="auth-title">Log in with your phone number</h2>
        <p class="auth-subtitle">Enter the mobile number of your account. We'll text you a 6-digit code — no password needed.</p>
    </div>

    <form method="POST" action="{{ route('login.phone.send') }}" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        <x-field
            name="phone"
            type="tel"
            label="Mobile number"
            icon="phone"
            placeholder="+92 300 1234567"
            autocomplete="tel"
            inputmode="tel"
            maxlength="20"
            hint="Include your country code, e.g. +92."
            required
            autofocus
        />

        <label class="checkbox">
            <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
            Remember me
        </label>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <x-icon name="message-square" /> Send code
        </button>
        <p class="form-hint text-center">SMS charges from your carrier may apply.</p>
    </form>

    <p class="auth-alt">
        <a href="{{ route('login') }}" class="auth-link">Log in with your password instead</a>
    </p>
</x-layouts.guest>
