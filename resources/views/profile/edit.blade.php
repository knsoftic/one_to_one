@php
    $tabs = [
        'profile' => ['label' => 'Profile', 'icon' => 'user-round'],
        'security' => ['label' => 'Password & security', 'icon' => 'lock'],
        'preferences' => ['label' => 'Appearance & alerts', 'icon' => 'palette'],
        'blocked' => ['label' => 'Blocked users', 'icon' => 'ban'],
    ];

    // Open the tab that has validation errors, otherwise the requested one.
    $activeTab = match (true) {
        $errors->getBag('profile')->isNotEmpty() => 'profile',
        $errors->getBag('password')->isNotEmpty() => 'security',
        $errors->getBag('preferences')->isNotEmpty() => 'preferences',
        default => array_key_exists(request('tab'), $tabs) ? request('tab') : 'profile',
    };
@endphp

<x-layouts.app title="Settings">
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
                            <div class="auth-grid auth-grid-2">
                                <x-field name="email" type="email" label="Email" icon="mail" :value="$user->email" bag="profile" autocomplete="email" maxlength="191" required />
                                <x-field name="phone" type="tel" label="Mobile number" icon="phone" :value="$user->phone" bag="profile" autocomplete="tel" maxlength="20" required />
                            </div>
                        </div>

                        <div class="card-footer">
                            <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save changes</button>
                        </div>
                    </form>
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
                            <h2 class="card-title">Blocked users</h2>
                            <p class="card-subtitle">Blocked users can't send you messages. Existing messages stay visible.</p>
                        </div>
                        <div class="card-body" data-blocked-list>
                            @forelse ($blockedUsers as $blocked)
                                <div class="list-row" data-blocked-row="{{ $blocked->id }}">
                                    <x-avatar :user="$blocked" size="md" />
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold truncate">{{ $blocked->name }}</div>
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
                                    <div class="empty-state-title">No blocked users</div>
                                    <div class="empty-state-text">People you block will appear here.</div>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-layouts.app>
