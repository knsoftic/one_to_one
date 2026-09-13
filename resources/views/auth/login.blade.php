<x-layouts.guest title="Sign in">
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

    <p class="auth-alt">
        Don't have an account?
        <a href="{{ route('register') }}" class="auth-link">Create one</a>
    </p>
</x-layouts.guest>
