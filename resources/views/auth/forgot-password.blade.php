<x-layouts.guest title="Forgot password">
    <div class="empty-state-icon" style="margin-bottom: 1.25rem"><x-icon name="key-round" /></div>
    <h2 class="auth-title">Forgot your password?</h2>
    <p class="auth-subtitle">Enter your email and we'll send you a link to reset it.@if (Route::has('login.phone') && app(\App\Services\SmsService::class)->available()) No email on your account? <a href="{{ route('login.phone') }}" class="auth-link">Log in with your phone number</a>.@endif</p>

    <form method="POST" action="{{ route('password.email') }}" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        <x-field name="email" type="email" label="Email" icon="mail" placeholder="you@example.com" autocomplete="email" required autofocus />

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <x-icon name="mail" />
            <span>Email reset link</span>
        </button>
    </form>

    <p class="auth-alt">
        <a href="{{ route('login') }}" class="auth-link inline-flex items-center gap-1"><x-icon name="arrow-left" class="icon-sm" /> Back to sign in</a>
    </p>
</x-layouts.guest>
