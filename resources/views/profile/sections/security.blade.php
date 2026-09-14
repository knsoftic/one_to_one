{{-- Security: password, where you're signed in (P9), two-step verification (P7) --}}

<div class="wa-group" id="password">
    <h3 class="wa-group-title">Change password</h3>
    <p class="wa-group-note is-top">Use a long, unique password to keep your account secure.</p>
    <form method="POST" action="{{ route('profile.password') }}" class="wa-form" data-loading-form novalidate>
        @csrf
        @method('PUT')
        <x-field name="current_password" type="password" label="Current password" icon="lock" bag="password" autocomplete="current-password" required />
        <x-field name="password" type="password" label="New password" icon="key-round" bag="password" autocomplete="new-password" data-strength-input required>
            <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </x-field>
        <x-field name="password_confirmation" type="password" label="Confirm new password" icon="key-round" bag="password" autocomplete="new-password" required />
        <div class="wa-form-actions"><button type="submit" class="btn btn-primary"><x-icon name="shield-check" /> Update password</button></div>
    </form>
</div>

{{-- Where you're signed in (P9) --}}
<div class="wa-group" id="sessions">
    <h3 class="wa-group-title">Where you're signed in</h3>
    @foreach ($sessions as $session)
        <div class="wa-row" data-session-row>
            <span class="session-icon"><x-icon :name="$session['mobile'] ? 'smartphone' : 'monitor'" /></span>
            <span class="wa-row-body">
                <span class="wa-row-title">
                    {{ $session['device'] }}
                    @if ($session['current'])
                        <span class="badge badge-success">This device</span>
                    @endif
                </span>
                <span class="wa-row-text">
                    {{ $session['ip'] ?? 'Unknown network' }} ·
                    {{ $session['current'] ? 'Active now' : 'Last active '.\Illuminate\Support\Carbon::parse($session['last_active'])->diffForHumans() }}
                </span>
            </span>
            @unless ($session['current'])
                <form method="POST" action="{{ route('sessions.destroy', $session['key']) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-secondary btn-sm">Log out</button>
                </form>
            @endunless
        </div>
    @endforeach
    @if ($sessions->count() > 1)
        <form method="POST" action="{{ route('sessions.others') }}" data-confirm="Sign out of every other browser and phone?">
            @csrf
            @method('DELETE')
            <button type="submit" class="wa-row is-link is-danger">
                <x-icon name="log-out" class="wa-row-icon" />
                <span class="wa-row-body"><span class="wa-row-title">Log out of all other devices</span></span>
            </button>
        </form>
    @endif
    <p class="wa-group-note">Sign out any browser or phone you don't recognise, then change your password.</p>
</div>

{{-- Two-step verification (P7) --}}
<div class="wa-group" id="two-step">
    <h3 class="wa-group-title">
        Two-step verification
        @if ($user->two_step_pin)
            <span class="badge badge-success">On</span>
        @else
            <span class="badge badge-muted">Off</span>
        @endif
    </h3>
    <p class="wa-group-note is-top">Ask for a 6-digit PIN when someone signs in with your password on a new device.</p>

    @if ($user->two_step_pin)
        <p class="wa-group-note is-top">On since {{ $user->two_step_enabled_at?->format('M j, Y') }} · {{ $user->trustedDevices()->count() === 1 ? '1 trusted browser' : $user->trustedDevices()->count().' trusted browsers' }}</p>

        <form method="POST" action="{{ route('two-step.change') }}" class="wa-form" data-loading-form novalidate>
            @csrf
            @method('PUT')
            <div class="wa-form-title">Change PIN</div>
            <x-field name="pin" type="password" label="New PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
            <x-field name="pin_confirmation" type="password" label="Confirm new PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
            <x-field name="current_password" id="two-step-change-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
            <div class="wa-form-actions"><button type="submit" class="btn btn-secondary"><x-icon name="refresh-cw" /> Change PIN</button></div>
        </form>

        <div class="wa-row">
            <span class="wa-row-body">
                <span class="wa-row-title">Ask again everywhere</span>
                <span class="wa-row-text">Every browser, including this one, will ask for the PIN at its next sign-in.</span>
            </span>
            <form method="POST" action="{{ route('two-step.devices.forget') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-secondary btn-sm">Forget browsers</button>
            </form>
        </div>

        <form method="POST" action="{{ route('two-step.disable') }}" class="wa-form" data-loading-form novalidate>
            @csrf
            @method('DELETE')
            <div class="wa-form-title">Turn off</div>
            <x-field name="current_password" id="two-step-disable-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
            <div class="wa-form-actions"><button type="submit" class="btn btn-danger-soft"><x-icon name="shield-off" /> Turn off two-step verification</button></div>
        </form>
    @else
        <form method="POST" action="{{ route('two-step.enable') }}" class="wa-form" data-loading-form novalidate>
            @csrf
            <x-field name="pin" type="password" label="Create a 6-digit PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
            <x-field name="pin_confirmation" type="password" label="Confirm PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
            <x-field name="current_password" id="two-step-enable-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
            <p class="form-hint">If you forget the PIN, you can turn it off with a link sent to {{ $user->email }}.</p>
            <div class="wa-form-actions"><button type="submit" class="btn btn-primary"><x-icon name="shield-check" /> Turn on</button></div>
        </form>
    @endif
</div>
