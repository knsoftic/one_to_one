<x-layouts.guest title="Reset password">
    <div class="empty-state-icon" style="margin-bottom: 1.25rem"><x-icon name="shield-check" /></div>
    <h2 class="auth-title">Set a new password</h2>
    <p class="auth-subtitle">Choose a strong password you haven't used before.</p>

    <form method="POST" action="{{ route('password.update') }}" class="auth-form" data-loading-form novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-alerts />

        <x-field name="email" type="email" label="Email" icon="mail" :value="$email" autocomplete="email" required />

        <x-field name="password" type="password" label="New password" icon="lock" placeholder="Min. 8 characters" autocomplete="new-password" data-strength-input required autofocus>
            <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </x-field>

        <x-field name="password_confirmation" type="password" label="Confirm new password" icon="lock" placeholder="Repeat password" autocomplete="new-password" required />

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <x-icon name="check" />
            <span>Reset password</span>
        </button>
    </form>
</x-layouts.guest>
