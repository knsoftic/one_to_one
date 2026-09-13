<x-layouts.guest title="Create account">
    <ol class="signup-steps" aria-label="Sign-up steps">
        <li class="is-current"><span>1</span> Your details</li>
        <li><span>2</span> Profile photo <em>(optional)</em></li>
    </ol>

    <h2 class="auth-title">Create your account</h2>
    <p class="auth-subtitle">Just your details — you can add a photo on the next step.</p>

    <form method="POST" action="{{ route('register.store') }}" class="auth-form" data-loading-form data-username-suggest novalidate>
        @csrf

        <x-alerts />

        <x-field name="name" label="Full name" icon="user" placeholder="Awais Ahmed" autocomplete="name" maxlength="100" required autofocus data-suggest-source />

        <x-field name="phone" type="tel" label="Mobile number" icon="phone" placeholder="+92 300 1234567" autocomplete="tel" inputmode="tel" maxlength="20" required
                 hint="People who have your number saved will find you in their contacts." />

        <x-field name="email" type="email" label="Email" icon="mail" placeholder="you@example.com" autocomplete="email" inputmode="email" autocapitalize="none" maxlength="191" required />

        <x-field name="username" label="Username" icon="at-sign" placeholder="awais.ahmed" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" required data-suggest-target
                 hint="Filled in from your name — change it if you like." />

        <div class="auth-grid auth-grid-2">
            <x-field name="password" type="password" label="Password" icon="lock" placeholder="Min. 8 characters" autocomplete="new-password" data-strength-input required>
                <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            </x-field>
            <x-field name="password_confirmation" type="password" label="Confirm password" icon="lock" placeholder="Repeat password" autocomplete="new-password" required />
        </div>

        <p class="form-hint -mt-1">At least 8 characters with upper &amp; lowercase letters and a number.</p>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <span>Continue</span>
            <x-icon name="arrow-left" class="rotate-180" />
        </button>
    </form>

    <p class="auth-alt">
        Already have an account?
        <a href="{{ route('login') }}" class="auth-link">Sign in</a>
    </p>
</x-layouts.guest>
