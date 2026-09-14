{{-- Account (Phase 7): change number, download my data, delete my account --}}

{{-- Change number (A2) --}}
<div class="wa-group" id="change-number">
    <h3 class="wa-group-title">Change number</h3>
    <div class="wa-row">
        <x-icon name="phone" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title wa-tabular">{{ $user->phone }}</span>
            <span class="wa-row-text">
                @if ($user->phone_verified_at)
                    <span class="badge badge-success">Verified by SMS</span>
                @else
                    Your current number
                @endif
            </span>
        </span>
    </div>
    <p class="wa-group-note">Move your account to a new mobile number. Your chats, groups, contacts and settings stay the same.</p>

    @if ($phoneChange)
        <form method="POST" action="{{ route('phone.change.verify') }}" class="wa-form" data-loading-form data-otp-form novalidate>
            @csrf
            <p class="wa-form-text">Enter the 6-digit code we sent to <strong class="otp-phone">{{ $phoneChange['phone'] }}</strong>. It expires in {{ \App\Services\OtpService::EXPIRES_MINUTES }} minutes.</p>
            <div class="form-group phone-change-code">
                <label for="phone-change-code" class="form-label">6-digit code</label>
                <input id="phone-change-code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                       class="form-control two-step-pin @error('code', 'phone') is-invalid @enderror" placeholder="• • • • • •" required autofocus data-otp-input>
                @error('code', 'phone')
                    <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
                @enderror
            </div>
            <div class="wa-form-actions"><button type="submit" class="btn btn-primary"><x-icon name="check" /> Change number</button></div>
        </form>
        <div class="wa-form wa-form-inline">
            <form method="POST" action="{{ route('phone.change.resend') }}" data-otp-resend data-wait="{{ $phoneResendIn }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm" data-otp-resend-button @disabled($phoneResendIn > 0)>
                    <x-icon name="refresh-cw" /> <span data-otp-resend-label>{{ $phoneResendIn > 0 ? "Send again in {$phoneResendIn}s" : 'Send again' }}</span>
                </button>
            </form>
            <form method="POST" action="{{ route('phone.change.cancel') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-ghost btn-sm">Use a different number</button>
            </form>
        </div>
    @else
        <form method="POST" action="{{ route('phone.change') }}" class="wa-form" data-loading-form novalidate>
            @csrf
            <x-field name="phone" id="change-phone" type="tel" label="New mobile number" icon="phone" bag="phone" placeholder="+92 300 1234567" autocomplete="tel" inputmode="tel" maxlength="20" required />
            <x-field name="current_password" id="change-phone-password" type="password" label="Current password" icon="lock" bag="phone" autocomplete="current-password" required />
            <p class="form-hint">Include the country code, e.g. +92.{{ $smsAvailable ? " We'll text a 6-digit code to the new number to confirm it." : '' }}</p>
            <div class="wa-form-actions"><button type="submit" class="btn btn-primary"><x-icon name="arrow-right-left" /> {{ $smsAvailable ? 'Send code' : 'Change number' }}</button></div>
        </form>
    @endif
</div>

{{-- Download my account data (A5) --}}
<div class="wa-group" id="account-data">
    <h3 class="wa-group-title">Download my account data</h3>
    <a href="{{ route('account.export') }}" class="wa-row is-link" data-account-export>
        <x-icon name="file-down" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Download report</span>
            <span class="wa-row-text">A page with your profile, privacy settings, contacts, groups and devices</span>
        </span>
    </a>
    <a href="{{ route('account.export', ['format' => 'json']) }}" class="wa-row is-link" data-account-export>
        <x-icon name="file-json" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Download as JSON</span>
            <span class="wa-row-text">The same report as a data file</span>
        </span>
    </a>
    <p class="wa-group-note">Your messages and files aren't included.</p>
</div>

{{-- Delete my account (A3) --}}
<div class="wa-group" id="delete-account">
    <h3 class="wa-group-title is-danger">Delete my account</h3>
    <ul class="wa-danger-list">
        <li><x-icon name="user-x" /> Your account, profile photo and settings are deleted</li>
        <li><x-icon name="message-square" /> Your chats, messages, status updates and files are removed</li>
        <li><x-icon name="users" /> You leave all your groups and communities (if you were the only admin, someone else becomes admin)</li>
        <li><x-icon name="megaphone" /> Your channels and broadcast lists are deleted</li>
    </ul>
    @if ($user->isAdmin())
        <p class="wa-group-note">Administrator accounts can't be deleted from settings.</p>
    @else
        <form method="POST" action="{{ route('account.destroy') }}" class="wa-form" data-loading-form novalidate
              data-confirm="Your account and everything above will be deleted for good. This can't be undone." data-confirm-title="Delete your account?" data-confirm-label="Delete my account" data-confirm-danger>
            @csrf
            @method('DELETE')
            <x-field name="current_password" id="delete-account-password" type="password" label="Current password" icon="lock" bag="deleteAccount" autocomplete="current-password" required />
            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" name="confirm" value="1">
                    I understand my account can't be recovered
                </label>
                @error('confirm', 'deleteAccount')
                    <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
                @enderror
            </div>
            <div class="wa-form-actions"><button type="submit" class="btn btn-danger"><x-icon name="trash-2" /> Delete my account</button></div>
        </form>
    @endif
</div>
