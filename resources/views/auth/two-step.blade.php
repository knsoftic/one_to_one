<x-layouts.guest title="Two-step verification">
    <div class="two-step-head">
        <span class="two-step-icon"><x-icon name="lock-keyhole" /></span>
        <h2 class="auth-title">Two-step verification</h2>
        <p class="auth-subtitle">Hi {{ \Illuminate\Support\Str::before($user->name, ' ') }}, enter the 6-digit PIN you created. We ask for it the first time you sign in on a device.</p>
    </div>

    <form method="POST" action="{{ route('two-step.verify') }}" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        <div class="form-group">
            <label for="two-step-pin" class="form-label">PIN</label>
            <input id="two-step-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                   class="form-control two-step-pin @error('pin') is-invalid @enderror" placeholder="• • • • • •" required autofocus>
            @error('pin')
                <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><x-icon name="shield-check" /> Verify</button>
    </form>

    <div class="auth-row mt-5">
        <form method="POST" action="{{ route('two-step.forgot') }}">
            @csrf
            <button type="submit" class="auth-link two-step-link">Forgot PIN?</button>
        </form>
        <a href="{{ route('login') }}" class="auth-link">Use another account</a>
    </div>
</x-layouts.guest>
