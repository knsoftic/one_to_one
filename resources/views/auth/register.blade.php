@php
    $emailMode = \App\Models\AppSetting::get('signup_email');
    $countryCode = \App\Models\AppSetting::get('default_country_code');
    // Refer & earn (Y2): the inviter's code from the link, the session or the cookie.
    $refCode = strtoupper((string) (old('ref') ?: request('ref') ?: \App\Http\Controllers\ReferralController::rememberedCode(request()) ?: ''));
    $refCode = preg_match('/^[A-Z2-9]{8}$/', $refCode) ? $refCode : '';
    $referrals = app(\App\Services\ReferralService::class);
    $inviter = $refCode !== '' && $referrals->enabled() ? $referrals->resolve($refCode) : null;
@endphp
<x-layouts.guest title="Create account">
    <ol class="signup-steps" aria-label="Sign-up steps">
        <li class="is-current"><span>1</span> Your details</li>
        <li><span>2</span> Profile photo <em>(optional)</em></li>
    </ol>

    <h2 class="auth-title">Create your account</h2>
    <p class="auth-subtitle">It takes a few seconds: your name, mobile number and a password.</p>

    <form method="POST" action="{{ route('register.store') }}" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        @if ($inviter)
            <input type="hidden" name="ref" value="{{ $refCode }}">
            <p class="form-hint auth-invited" data-invited-by><x-icon name="gift" /> Invited by <strong>{{ $inviter->name }}</strong></p>
        @elseif ($refCode !== '')
            <input type="hidden" name="ref" value="{{ $refCode }}">
        @endif

        <x-field name="name" label="Your name" icon="user" placeholder="Awais Ahmed" autocomplete="name" maxlength="100" required autofocus />

        <x-field name="phone" type="tel" label="Mobile number" icon="phone" :placeholder="$countryCode === '+92' ? '0300 1234567' : ($countryCode ? $countryCode.' …' : '+92 300 1234567')" autocomplete="tel" inputmode="tel" maxlength="20" required
                 :hint="'People who saved your number will find you.'.($countryCode ? ' '.$countryCode.' is added for you — for another country start with +.' : '')" />

        @if ($emailMode !== 'hidden')
            <x-field name="email" type="email" label="Email" icon="mail" placeholder="you@example.com" autocomplete="email" inputmode="email" autocapitalize="none" maxlength="191"
                     :optional="$emailMode !== 'required'" :required="$emailMode === 'required'"
                     hint="Only used to reset your password." />
        @endif

        <x-field name="password" type="password" label="Password" icon="lock" placeholder="At least 8 letters and numbers" autocomplete="new-password" data-strength-input required
                 hint="At least 8 characters with a letter and a number. Tap the eye to see what you typed.">
            <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </x-field>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <span>Create account</span>
            <x-icon name="arrow-left" class="rotate-180" />
        </button>

        <p class="form-hint text-center">Your username is made from your name — you can change it later in Settings.</p>
        <p class="form-hint text-center">By creating an account you agree to the <a href="{{ route('legal', 'terms') }}" class="auth-link">Terms</a> and <a href="{{ route('legal', 'privacy') }}" class="auth-link">Privacy policy</a>.</p>
    </form>

    <p class="auth-alt">
        Already have an account?
        <a href="{{ route('login') }}" class="auth-link">Sign in</a>
    </p>
</x-layouts.guest>
