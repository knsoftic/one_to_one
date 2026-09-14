@php
    $tabs = [
        'profile' => ['label' => 'Profile', 'icon' => 'user-round'],
        'privacy' => ['label' => 'Privacy', 'icon' => 'shield'],
        'security' => ['label' => 'Password & security', 'icon' => 'lock'],
        'account' => ['label' => 'Account', 'icon' => 'user-cog'],
        'preferences' => ['label' => 'Appearance & alerts', 'icon' => 'palette'],
        'blocked' => ['label' => 'Blocked contacts', 'icon' => 'ban'],
    ];

    // Open the tab that has validation errors, otherwise the requested one.
    $activeTab = match (true) {
        $errors->getBag('profile')->isNotEmpty() => 'profile',
        $errors->getBag('password')->isNotEmpty() => 'security',
        $errors->getBag('twoStep')->isNotEmpty() => 'security',
        $errors->getBag('phone')->isNotEmpty() => 'account',
        $errors->getBag('deleteAccount')->isNotEmpty() => 'account',
        $phoneChange !== null && ! request()->has('tab') => 'account',
        $errors->getBag('preferences')->isNotEmpty() => 'preferences',
        default => array_key_exists(request('tab'), $tabs) ? request('tab') : 'profile',
    };
@endphp

<x-layouts.app title="Settings" :scripts="$phoneChange ? ['resources/js/auth/otp.js'] : []">
    <div class="page-container">
        <div class="page-header">
            <div>
                <h1 class="page-title">Settings</h1>
                <p class="page-subtitle">Manage your profile, security and app preferences.</p>
            </div>
        </div>

        <div class="mb-5"><x-alerts /></div>

        <div class="settings-layout" data-settings-tabs>
            {{-- Navigation --}}
            <div>
                <div class="card settings-profile-card">
                    <x-avatar :user="$user" size="xl" />
                    <div class="font-bold mt-2">{{ $user->name }}</div>
                    <div class="text-sm text-muted">{{ '@'.$user->username }}</div>
                </div>

                <nav class="settings-nav" role="tablist" aria-label="Settings sections">
                    @foreach ($tabs as $key => $tab)
                        <button type="button" class="tab" role="tab" data-tab="{{ $key }}"
                                aria-selected="{{ $activeTab === $key ? 'true' : 'false' }}"
                                aria-controls="section-{{ $key }}">
                            <x-icon :name="$tab['icon']" /> {{ $tab['label'] }}
                        </button>
                    @endforeach

                    <form method="POST" action="{{ route('logout') }}" class="contents">
                        @csrf
                        <button type="submit" class="tab text-danger!"><x-icon name="log-out" /> Log out</button>
                    </form>
                </nav>
            </div>

            <div>
                {{-- Profile --}}
                <section id="section-profile" role="tabpanel" data-tab-panel="profile" @class(['settings-section', 'is-active' => $activeTab === 'profile'])>
                    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="card" data-loading-form novalidate>
                        @csrf
                        @method('PUT')

                        <div class="card-header">
                            <h2 class="card-title">Profile information</h2>
                            <p class="card-subtitle">This is how other people see you in chats.</p>
                        </div>

                        <div class="card-body flex flex-col gap-5">
                            <div class="form-group">
                                <span class="form-label">Profile picture</span>
                                <div class="avatar-picker" data-avatar-picker>
                                    <label for="profile-image-input" @class(['avatar-picker-preview', 'has-image' => $user->avatar_url]) data-avatar-preview>
                                        <x-icon name="camera" class="icon-lg" data-avatar-placeholder :hidden="(bool) $user->avatar_url" />
                                        <img src="{{ $user->avatar_url }}" alt="" @unless ($user->avatar_url) hidden @endunless data-avatar-image>
                                    </label>
                                    <div class="flex flex-col gap-1.5">
                                        <div class="flex flex-wrap gap-2">
                                            <label for="profile-image-input" class="btn btn-secondary btn-sm cursor-pointer">
                                                <x-icon name="upload" /> Change photo
                                            </label>
                                            <button type="button" class="btn btn-ghost btn-sm" data-avatar-clear @unless ($user->avatar_url) hidden @endunless>
                                                <x-icon name="trash-2" /> Remove
                                            </button>
                                        </div>
                                        <span class="avatar-picker-meta">JPG, PNG or WEBP · max {{ (int) (config('chat.uploads.avatar.max_kb') / 1024) }} MB</span>
                                    </div>
                                    <input id="profile-image-input" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only" data-avatar-input>
                                    <input type="hidden" name="remove_profile_image" value="0" data-avatar-remove>
                                </div>
                                @error('profile_image', 'profile')
                                    <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="auth-grid auth-grid-2">
                                <x-field name="name" label="Full name" icon="user" :value="$user->name" bag="profile" autocomplete="name" maxlength="100" required />
                                <x-field name="username" label="Username" icon="at-sign" :value="$user->username" bag="profile" autocomplete="username" autocapitalize="none" maxlength="30" required />
                            </div>
                            <x-field name="about" label="About" icon="info" :value="$user->about" bag="profile" maxlength="139" placeholder="Busy, At work, Hey there!…" optional />
                            <div class="auth-grid auth-grid-2">
                                <x-field name="email" type="email" label="Email" icon="mail" :value="$user->email" bag="profile" autocomplete="email" maxlength="191" required />
                                <div class="form-group">
                                    <span class="form-label">Mobile number</span>
                                    <div class="profile-phone">
                                        <x-icon name="phone" />
                                        <span class="truncate">{{ $user->phone }}</span>
                                        <button type="button" class="btn btn-ghost btn-sm" data-tab="account">Change</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save changes</button>
                        </div>
                    </form>
                </section>

                {{-- Privacy (Phase 6) --}}
                @php
                    $privacyOptions = ['everyone' => 'Everyone', 'contacts' => 'My contacts', 'nobody' => 'Nobody'];
                @endphp
                <section id="section-privacy" role="tabpanel" data-tab-panel="privacy" @class(['settings-section', 'is-active' => $activeTab === 'privacy'])>
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Privacy</h2>
                            <p class="card-subtitle">Choose who can see your details. "My contacts" means people you saved and people you chat with. Changes are saved automatically.</p>
                        </div>
                        <div class="card-body">
                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">Last seen</div>
                                    <div class="setting-text">If you don't share your last seen with anyone, you won't see other people's.</div>
                                </div>
                                <select class="form-control setting-select" data-preference="last_seen_privacy" aria-label="Who can see my last seen">
                                    @foreach ($privacyOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($user->last_seen_privacy === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">Online</div>
                                    <div class="setting-text">Who can see when you're online.</div>
                                </div>
                                <select class="form-control setting-select" data-preference="online_privacy" aria-label="Who can see when I'm online">
                                    <option value="everyone" @selected($user->online_privacy === 'everyone')>Everyone</option>
                                    <option value="same" @selected($user->online_privacy === 'same')>Same as last seen</option>
                                </select>
                            </div>

                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">Profile photo</div>
                                    <div class="setting-text">People who can't see it get your initials instead.</div>
                                </div>
                                <select class="form-control setting-select" data-preference="photo_privacy" aria-label="Who can see my profile photo">
                                    @foreach ($privacyOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($user->photo_privacy === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">About</div>
                                    <div class="setting-text">The short text on your profile.</div>
                                </div>
                                <select class="form-control setting-select" data-preference="about_privacy" aria-label="Who can see my About">
                                    @foreach ($privacyOptions as $value => $label)
                                        <option value="{{ $value }}" @selected($user->about_privacy === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-title">Read receipts</div>
                                    <div class="setting-text">If turned off, you won't send or receive blue ticks in one-to-one chats. Group chats always show them.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" data-preference="read_receipts" @checked($user->read_receipts) aria-label="Read receipts">
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-title">Status</div>
                                    <div class="setting-text">Choose who sees your status updates from Status → the lock button in the chats.</div>
                                </div>
                            </div>

                            {{-- App lock (P8): shown only inside the Android app --}}
                            <div class="setting-row stack-mobile" data-app-lock-row hidden>
                                <div>
                                    <div class="setting-title">App lock</div>
                                    <div class="setting-text" data-app-lock-text>Use your fingerprint, face or phone screen lock to open the app on this phone.</div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <select class="form-control setting-select" data-app-lock-timeout aria-label="Lock the app">
                                        <option value="0">Immediately</option>
                                        <option value="60">After 1 minute</option>
                                        <option value="1800">After 30 minutes</option>
                                    </select>
                                    <label class="switch">
                                        <input type="checkbox" data-app-lock-toggle aria-label="App lock">
                                        <span class="switch-track"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-title">Blocked contacts</div>
                                    <div class="setting-text">{{ $blockedUsers->count() === 1 ? '1 person' : $blockedUsers->count().' people' }}</div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" data-tab="blocked"><x-icon name="ban" /> View</button>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Security --}}
                <section id="section-security" role="tabpanel" data-tab-panel="security" @class(['settings-section', 'is-active' => $activeTab === 'security'])>
                    <form method="POST" action="{{ route('profile.password') }}" class="card" data-loading-form novalidate>
                        @csrf
                        @method('PUT')

                        <div class="card-header">
                            <h2 class="card-title">Change password</h2>
                            <p class="card-subtitle">Use a long, unique password to keep your account secure.</p>
                        </div>

                        <div class="card-body flex flex-col gap-5">
                            <x-field name="current_password" type="password" label="Current password" icon="lock" bag="password" autocomplete="current-password" required />
                            <div class="auth-grid auth-grid-2">
                                <x-field name="password" type="password" label="New password" icon="key-round" bag="password" autocomplete="new-password" data-strength-input required>
                                    <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                                </x-field>
                                <x-field name="password_confirmation" type="password" label="Confirm new password" icon="key-round" bag="password" autocomplete="new-password" required />
                            </div>
                        </div>

                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary"><x-icon name="shield-check" /> Update password</button>
                        </div>
                    </form>

                    {{-- Where you're signed in (P9) --}}
                    <div class="card mt-5" id="sessions">
                        <div class="card-header">
                            <h2 class="card-title">Where you're signed in</h2>
                            <p class="card-subtitle">Browsers and phones with an open session. Sign out any you don't recognise, then change your password.</p>
                        </div>
                        <div class="card-body">
                            @foreach ($sessions as $session)
                                <div class="list-row" data-session-row>
                                    <span class="session-icon"><x-icon :name="$session['mobile'] ? 'smartphone' : 'monitor'" /></span>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">
                                            {{ $session['device'] }}
                                            @if ($session['current'])
                                                <span class="badge badge-success">This device</span>
                                            @endif
                                        </div>
                                        <div class="text-sm text-muted truncate">
                                            {{ $session['ip'] ?? 'Unknown network' }} ·
                                            {{ $session['current'] ? 'Active now' : 'Last active '.\Illuminate\Support\Carbon::parse($session['last_active'])->diffForHumans() }}
                                        </div>
                                    </div>
                                    @unless ($session['current'])
                                        <form method="POST" action="{{ route('sessions.destroy', $session['key']) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="log-out" /> Log out</button>
                                        </form>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                        @if ($sessions->count() > 1)
                            <div class="card-footer">
                                <form method="POST" action="{{ route('sessions.others') }}" data-confirm="Sign out of every other browser and phone?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-danger-soft"><x-icon name="log-out" /> Log out of all other devices</button>
                                </form>
                            </div>
                        @endif
                    </div>

                    {{-- Two-step verification (P7) --}}
                    <div class="card mt-5" id="two-step">
                        <div class="card-header">
                            <h2 class="card-title flex items-center gap-2">
                                Two-step verification
                                @if ($user->two_step_pin)
                                    <span class="badge badge-success">On</span>
                                @else
                                    <span class="badge badge-muted">Off</span>
                                @endif
                            </h2>
                            <p class="card-subtitle">Ask for a 6-digit PIN when someone signs in with your password on a new device.</p>
                        </div>
                        <div class="card-body flex flex-col gap-5">
                            @if ($user->two_step_pin)
                                <p class="text-sm text-muted">On since {{ $user->two_step_enabled_at?->format('M j, Y') }} · {{ $user->trustedDevices()->count() === 1 ? '1 trusted browser' : $user->trustedDevices()->count().' trusted browsers' }}</p>

                                <form method="POST" action="{{ route('two-step.change') }}" class="flex flex-col gap-4" data-loading-form novalidate>
                                    @csrf
                                    @method('PUT')
                                    <div class="setting-title">Change PIN</div>
                                    <div class="auth-grid auth-grid-2">
                                        <x-field name="pin" type="password" label="New PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
                                        <x-field name="pin_confirmation" type="password" label="Confirm new PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
                                    </div>
                                    <x-field name="current_password" id="two-step-change-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
                                    <div><button type="submit" class="btn btn-secondary"><x-icon name="refresh-cw" /> Change PIN</button></div>
                                </form>

                                <div class="setting-row">
                                    <div>
                                        <div class="setting-title">Ask again everywhere</div>
                                        <div class="setting-text">Every browser, including this one, will ask for the PIN at its next sign-in.</div>
                                    </div>
                                    <form method="POST" action="{{ route('two-step.devices.forget') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-secondary btn-sm">Forget browsers</button>
                                    </form>
                                </div>

                                <form method="POST" action="{{ route('two-step.disable') }}" class="flex flex-col gap-3" data-loading-form novalidate>
                                    @csrf
                                    @method('DELETE')
                                    <div class="setting-title">Turn off</div>
                                    <x-field name="current_password" id="two-step-disable-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
                                    <div><button type="submit" class="btn btn-danger-soft"><x-icon name="shield-off" /> Turn off two-step verification</button></div>
                                </form>
                            @else
                                <form method="POST" action="{{ route('two-step.enable') }}" class="flex flex-col gap-4" data-loading-form novalidate>
                                    @csrf
                                    <div class="auth-grid auth-grid-2">
                                        <x-field name="pin" type="password" label="Create a 6-digit PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
                                        <x-field name="pin_confirmation" type="password" label="Confirm PIN" icon="key-round" bag="twoStep" inputmode="numeric" maxlength="6" autocomplete="off" required />
                                    </div>
                                    <x-field name="current_password" id="two-step-enable-password" type="password" label="Current password" icon="lock" bag="twoStep" autocomplete="current-password" required />
                                    <p class="form-hint">If you forget the PIN, you can turn it off with a link sent to {{ $user->email }}.</p>
                                    <div><button type="submit" class="btn btn-primary"><x-icon name="shield-check" /> Turn on</button></div>
                                </form>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Account (Phase 7) --}}
                <section id="section-account" role="tabpanel" data-tab-panel="account" @class(['settings-section', 'is-active' => $activeTab === 'account'])>
                    {{-- Change number (A2) --}}
                    <div class="card" id="change-number">
                        <div class="card-header">
                            <h2 class="card-title">Change number</h2>
                            <p class="card-subtitle">Move your account to a new mobile number. Your chats, groups, contacts and settings stay the same.</p>
                        </div>
                        <div class="card-body flex flex-col gap-5">
                            <div class="account-number">
                                <span class="session-icon"><x-icon name="phone" /></span>
                                <div class="min-w-0">
                                    <div class="setting-title">{{ $user->phone }}</div>
                                    <div class="setting-text">
                                        @if ($user->phone_verified_at)
                                            <span class="badge badge-success">Verified by SMS</span>
                                        @else
                                            Your current number
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if ($phoneChange)
                                <form method="POST" action="{{ route('phone.change.verify') }}" class="flex flex-col gap-4" data-loading-form data-otp-form novalidate>
                                    @csrf
                                    <p class="text-sm">Enter the 6-digit code we sent to <strong class="otp-phone">{{ $phoneChange['phone'] }}</strong>. It expires in {{ \App\Services\OtpService::EXPIRES_MINUTES }} minutes.</p>
                                    <div class="form-group phone-change-code">
                                        <label for="phone-change-code" class="form-label">6-digit code</label>
                                        <input id="phone-change-code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                                               class="form-control two-step-pin @error('code', 'phone') is-invalid @enderror" placeholder="• • • • • •" required autofocus data-otp-input>
                                        @error('code', 'phone')
                                            <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div><button type="submit" class="btn btn-primary"><x-icon name="check" /> Change number</button></div>
                                </form>
                                <div class="flex flex-wrap items-center gap-2">
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
                                <form method="POST" action="{{ route('phone.change') }}" class="flex flex-col gap-4" data-loading-form novalidate>
                                    @csrf
                                    <div class="auth-grid auth-grid-2">
                                        <x-field name="phone" id="change-phone" type="tel" label="New mobile number" icon="phone" bag="phone" placeholder="+92 300 1234567" autocomplete="tel" inputmode="tel" maxlength="20" required />
                                        <x-field name="current_password" id="change-phone-password" type="password" label="Current password" icon="lock" bag="phone" autocomplete="current-password" required />
                                    </div>
                                    <p class="form-hint">Include the country code, e.g. +92.{{ $smsAvailable ? " We'll text a 6-digit code to the new number to confirm it." : '' }}</p>
                                    <div><button type="submit" class="btn btn-primary"><x-icon name="arrow-right-left" /> {{ $smsAvailable ? 'Send code' : 'Change number' }}</button></div>
                                </form>
                            @endif
                        </div>
                    </div>

                    {{-- Profile QR code (A4) --}}
                    <div class="card mt-5" id="qr-code">
                        <div class="card-header">
                            <h2 class="card-title">QR code</h2>
                            <p class="card-subtitle">Anyone who scans your code with {{ config('app.name') }} can start a chat with you. Only share it with people you trust.</p>
                        </div>
                        <div class="card-body profile-qr">
                            <div class="profile-qr-card">
                                <x-avatar :user="$user" size="lg" />
                                <div class="font-bold">{{ $user->name }}</div>
                                <div class="profile-qr-box" data-profile-qr="{{ $qrUrl }}" role="img" aria-label="Your QR code"><span class="spinner"></span></div>
                            </div>
                            <div class="flex flex-col gap-3 min-w-0">
                                <p class="text-sm text-muted">In the app, tap <strong>Menu ⋮</strong> → <strong>QR code</strong> → <strong>Scan code</strong> to open a chat from someone's code.</p>
                                <div class="profile-qr-link">
                                    <code data-profile-qr-link>{{ $qrUrl }}</code>
                                    <button type="button" class="btn btn-secondary btn-sm" data-copy-text="{{ $qrUrl }}"><x-icon name="copy" /> Copy link</button>
                                </div>
                                <form method="POST" action="{{ route('profile-qr.reset') }}" data-confirm="Your current QR code and link will stop working. People who already chat with you aren't affected." data-confirm-title="Reset your QR code?" data-confirm-label="Reset">
                                    @csrf
                                    <button type="submit" class="btn btn-ghost btn-sm"><x-icon name="refresh-cw" /> Reset QR code</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    {{-- Download my account data (A5) --}}
                    <div class="card mt-5" id="account-data">
                        <div class="card-header">
                            <h2 class="card-title">Download my account data</h2>
                            <p class="card-subtitle">A report of your account: profile, privacy settings, contacts, blocked people, groups, communities, channels and where you're signed in. Your messages and files aren't included.</p>
                        </div>
                        <div class="card-body flex flex-wrap gap-2">
                            <a href="{{ route('account.export') }}" class="btn btn-primary" data-account-export><x-icon name="file-down" /> Download report</a>
                            <a href="{{ route('account.export', ['format' => 'json']) }}" class="btn btn-secondary" data-account-export><x-icon name="file-json" /> Download as JSON</a>
                        </div>
                    </div>

                    {{-- Delete my account (A3) --}}
                    <div class="card mt-5 account-danger" id="delete-account">
                        <div class="card-header">
                            <h2 class="card-title text-danger">Delete my account</h2>
                            <p class="card-subtitle">This can't be undone.</p>
                        </div>
                        <div class="card-body flex flex-col gap-4">
                            <ul class="account-danger-list">
                                <li><x-icon name="user-x" /> Your account, profile photo and settings are deleted</li>
                                <li><x-icon name="message-square" /> Your chats, messages, status updates and files are removed</li>
                                <li><x-icon name="users" /> You leave all your groups and communities (if you were the only admin, someone else becomes admin)</li>
                                <li><x-icon name="megaphone" /> Your channels and broadcast lists are deleted</li>
                            </ul>
                            @if ($user->isAdmin())
                                <p class="form-hint">Administrator accounts can't be deleted from settings.</p>
                            @else
                                <form method="POST" action="{{ route('account.destroy') }}" class="flex flex-col gap-4" data-loading-form novalidate
                                      data-confirm="Your account and everything above will be deleted for good." data-confirm-title="Delete your account?" data-confirm-label="Delete my account" data-confirm-danger>
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
                                    <div><button type="submit" class="btn btn-danger"><x-icon name="trash-2" /> Delete my account</button></div>
                                </form>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Preferences --}}
                <section id="section-preferences" role="tabpanel" data-tab-panel="preferences" @class(['settings-section', 'is-active' => $activeTab === 'preferences'])>
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Appearance &amp; notifications</h2>
                            <p class="card-subtitle">Changes are saved automatically.</p>
                        </div>
                        <div class="card-body">
                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">Theme</div>
                                    <div class="setting-text">Choose light, dark, or follow your device setting.</div>
                                </div>
                                <div class="segmented" role="group" aria-label="Theme" data-theme-picker>
                                    <button type="button" data-theme-option="light" aria-pressed="{{ $user->theme === 'light' ? 'true' : 'false' }}"><x-icon name="sun" /> Light</button>
                                    <button type="button" data-theme-option="dark" aria-pressed="{{ $user->theme === 'dark' ? 'true' : 'false' }}"><x-icon name="moon" /> Dark</button>
                                    <button type="button" data-theme-option="system" aria-pressed="{{ $user->theme === 'system' ? 'true' : 'false' }}"><x-icon name="monitor" /> System</button>
                                </div>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-title">New message notifications</div>
                                    <div class="setting-text">Show in-app and desktop alerts when someone messages you.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" data-preference="notifications_enabled" @checked($user->notifications_enabled) aria-label="New message notifications">
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="setting-row">
                                <div>
                                    <div class="setting-title">Notification sound</div>
                                    <div class="setting-text">Play a short sound for incoming messages.</div>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" data-preference="notification_sound" @checked($user->notification_sound) aria-label="Notification sound">
                                    <span class="switch-track"></span>
                                </label>
                            </div>

                            <div class="setting-row stack-mobile">
                                <div>
                                    <div class="setting-title">Desktop notifications</div>
                                    <div class="setting-text" data-browser-permission-text>Allow your browser to show notifications while the app is in the background.</div>
                                </div>
                                <button type="button" class="btn btn-secondary btn-sm" data-request-browser-notifications>
                                    <x-icon name="bell" /> Enable
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Blocked users --}}
                <section id="section-blocked" role="tabpanel" data-tab-panel="blocked" @class(['settings-section', 'is-active' => $activeTab === 'blocked'])>
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">Blocked contacts <span class="text-muted font-normal">({{ $blockedUsers->count() }})</span></h2>
                            <p class="card-subtitle">Blocked contacts can't call you or send you messages, and don't see your last seen, online, profile photo, About or status. Existing messages stay visible.</p>
                        </div>
                        <div class="card-body" data-blocked-list>
                            @if ($blockCandidates->isNotEmpty() && Route::has('blocks.store'))
                                <details class="block-picker">
                                    <summary class="btn btn-secondary btn-sm"><x-icon name="user-x" /> Block someone</summary>
                                    <div class="block-picker-panel">
                                        <div class="input-wrap">
                                            <x-icon name="search" />
                                            <input type="search" class="form-control" placeholder="Search people you chat with" aria-label="Search people you chat with" data-block-search>
                                        </div>
                                        <div class="block-picker-list">
                                            @foreach ($blockCandidates as $candidate)
                                                <div class="list-row" data-block-candidate data-name="{{ mb_strtolower(($savedNames[$candidate->id] ?? $candidate->name).' '.$candidate->username) }}">
                                                    <x-avatar :user="$candidate" size="sm" />
                                                    <div class="flex-1 min-w-0">
                                                        <div class="font-semibold truncate">{{ $savedNames[$candidate->id] ?? $candidate->name }}</div>
                                                        <div class="text-sm text-muted truncate">{{ '@'.$candidate->username }}</div>
                                                    </div>
                                                    <form method="POST" action="{{ route('blocks.store', $candidate) }}" data-confirm="Block {{ $savedNames[$candidate->id] ?? $candidate->name }}? They won't be able to call you or send you messages.">
                                                        @csrf
                                                        <button type="submit" class="btn btn-danger-soft btn-sm"><x-icon name="ban" /> Block</button>
                                                    </form>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </details>
                            @endif

                            @forelse ($blockedUsers as $blocked)
                                <div class="list-row" data-blocked-row="{{ $blocked->id }}">
                                    <x-avatar :user="$blocked" size="md" />
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $savedNames[$blocked->id] ?? $blocked->name }}</div>
                                        <div class="text-sm text-muted truncate">{{ '@'.$blocked->username }} · blocked {{ \Illuminate\Support\Carbon::parse($blocked->pivot->created_at)->diffForHumans() }}</div>
                                    </div>
                                    @if (Route::has('blocks.destroy'))
                                        <form method="POST" action="{{ route('blocks.destroy', $blocked) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="undo-2" /> Unblock</button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <div class="empty-state">
                                    <div class="empty-state-icon"><x-icon name="shield-check" /></div>
                                    <div class="empty-state-title">No blocked contacts</div>
                                    <div class="empty-state-text">People you block will appear here. You can also block someone from their chat.</div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
