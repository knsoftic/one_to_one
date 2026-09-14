<x-layouts.guest title="Enter the code" :scripts="['resources/js/auth/otp.js']">
    <div class="two-step-head">
        <span class="two-step-icon"><x-icon name="message-square" /></span>
        <h2 class="auth-title">Enter the code</h2>
        <p class="auth-subtitle">If <strong class="otp-phone">{{ $phone }}</strong> belongs to an account, we've sent it a 6-digit code by SMS. It expires in {{ \App\Services\OtpService::EXPIRES_MINUTES }} minutes.</p>
    </div>

    <form method="POST" action="{{ route('login.phone.verify') }}" class="auth-form" data-loading-form data-otp-form novalidate>
        @csrf

        <x-alerts />

        <div class="form-group">
            <label for="otp-code" class="form-label">6-digit code</label>
            <input id="otp-code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                   class="form-control two-step-pin @error('code') is-invalid @enderror" placeholder="• • • • • •" required autofocus data-otp-input>
            @error('code')
                <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg btn-block"><x-icon name="log-in" /> Log in</button>
    </form>

    <div class="auth-row mt-5">
        <form method="POST" action="{{ route('login.phone.resend') }}" data-otp-resend data-wait="{{ $resendIn }}">
            @csrf
            <button type="submit" class="auth-link two-step-link" data-otp-resend-button @disabled($resendIn > 0)>
                Didn't get it? <span data-otp-resend-label>{{ $resendIn > 0 ? "Send again in {$resendIn}s" : 'Send again' }}</span>
            </button>
        </form>
        <a href="{{ route('login.phone') }}" class="auth-link">Wrong number?</a>
    </div>
</x-layouts.guest>
