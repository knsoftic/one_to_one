<div class="admin-profile">
    {{-- ======================== Left: profile ======================== --}}
    <div class="admin-stack">
        <section class="card admin-profile-card">
            <x-avatar :user="$user" size="2xl" status />
            <h2 class="admin-profile-name">{{ $user->name }}</h2>
            <p class="admin-profile-handle">{{ '@'.$user->username }}</p>
            @if ($user->about)
                <p class="admin-profile-about">{{ $user->about }}</p>
            @endif
            <div class="admin-badges justify-center"><x-admin.status-badge :user="$user" /></div>
            <div class="admin-profile-actions">
                <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'chats']) }}" class="btn btn-primary btn-sm"><x-icon name="message-circle" /> View chats</a>
                <x-admin.user-actions :user="$user" />
            </div>
        </section>

        @if ($user->isBanned())
            <section class="card admin-ban-card">
                <div class="card-body">
                    <h3 class="admin-section-title is-danger"><x-icon name="shield-ban" /> Banned</h3>
                    <dl class="admin-details">
                        <div><dt>Reason</dt><dd>{{ $user->ban_reason ?: '—' }}</dd></div>
                        <div><dt>Ends</dt><dd>{{ $user->banned_until ? $user->banned_until->format('j M Y, g:i A').' ('.$user->banned_until->diffForHumans().')' : 'Never (permanent)' }}</dd></div>
                        <div><dt>Since</dt><dd>{{ $user->banned_at?->format('j M Y, g:i A') ?? '—' }}</dd></div>
                        <div><dt>By</dt><dd>{{ $user->bannedBy?->name ?? '—' }}</dd></div>
                    </dl>
                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.users.unban', $user) }}" class="mt-4" data-confirm="{{ $user->name }} will be able to sign in and use the app again." data-confirm-title="Lift the ban?" data-confirm-label="Lift ban">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-secondary btn-block"><x-icon name="lock-open" /> Lift ban</button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title">Details</h3>
                <dl class="admin-details">
                    <div><dt><x-icon name="mail" /> Email</dt><dd>{{ $user->email ?? '—' }} @if ($user->email_verified_at)<span class="badge badge-success">Verified</span>@endif</dd></div>
                    <div><dt><x-icon name="phone" /> Mobile</dt><dd class="tabular-nums">{{ $user->phone }} @if ($user->phone_verified_at)<span class="badge badge-success">SMS</span>@endif</dd></div>
                    <div><dt><x-icon name="clock" /> Last seen</dt><dd>{{ $user->isOnlineNow() ? 'Online now' : ($user->last_seen?->format('j M Y, g:i A') ?? 'Never') }}</dd></div>
                    <div><dt><x-icon name="log-in" /> Last sign-in</dt><dd>{{ $lastLogin ? $lastLogin->created_at->format('j M Y, g:i A').' · '.$lastLogin->methodLabel().($lastLogin->ip_address ? ' · '.$lastLogin->ip_address : '') : '—' }}</dd></div>
                    <div><dt><x-icon name="user-plus" /> Joined</dt><dd>{{ $user->created_at->format('j M Y, g:i A') }}</dd></div>
                    <div><dt><x-icon name="shield-check" /> Two-step</dt><dd>{{ $twoStep ? 'On' : 'Off' }}</dd></div>
                    <div><dt><x-icon name="lock" /> Privacy</dt><dd>Last seen: {{ $user->last_seen_privacy }} · Photo: {{ $user->photo_privacy }}</dd></div>
                    <div><dt><x-icon name="message-square-warning" /> Reports</dt><dd>{{ $counts['reports_against'] }} about this person · {{ $counts['reports_made'] }} made</dd></div>
                    @if ($counts['failed_logins_7d'])
                        <div><dt><x-icon name="triangle-alert" /> Wrong passwords</dt><dd><span class="badge badge-warning">{{ $counts['failed_logins_7d'] }} in the last 7 days</span></dd></div>
                    @endif
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card-body">
                <div class="admin-card-head-inline">
                    <h3 class="admin-section-title">Signed in on</h3>
                    <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'devices']) }}" class="admin-link text-sm">All devices</a>
                </div>
                @forelse ($sessions as $session)
                    <div class="admin-list-row is-compact">
                        <span class="session-icon"><x-icon :name="$session['mobile'] ? 'smartphone' : 'monitor'" /></span>
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $session['device'] }}</span>
                            <span class="admin-list-text">{{ $session['ip'] ?? 'Unknown network' }} · {{ \Illuminate\Support\Carbon::parse($session['last_active'])->diffForHumans() }}</span>
                        </span>
                    </div>
                @empty
                    <p class="admin-muted">Not signed in anywhere.</p>
                @endforelse
            </div>
        </section>
    </div>

    {{-- ======================== Right: numbers and tools ======================== --}}
    <div class="admin-stack">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">In numbers</h2>
                    <p class="card-subtitle">Everything this account did. Tap a number for the details.</p>
                </div>
            </div>
            <div class="admin-numbers">
                @foreach ([
                    'Messages' => [
                        ['Sent', number_format($counts['messages_sent']), 'send-horizontal', 'activity'],
                        ['Sent in 30 days', number_format($counts['messages_30d']), 'trending-up', 'activity'],
                        ['Received (1:1)', number_format($counts['messages_received']), 'message-square-text', 'activity'],
                        ['Photos, videos & files', number_format($counts['media_files']).' · '.$bytes($counts['media_bytes']), 'images', 'settings'],
                    ],
                    'Chats & groups' => [
                        ['Chats', number_format($counts['chats']), 'message-circle', 'chats'],
                        ['Groups', number_format($counts['groups']).($counts['groups_created'] ? ' · '.$counts['groups_created'].' made' : ''), 'users-round', 'groups'],
                        ['Communities', number_format($counts['communities']), 'layers', 'groups'],
                        ['Channels', number_format($counts['channels']), 'rss', 'groups'],
                        ['Status updates', number_format($counts['statuses']), 'circle-dashed', null],
                    ],
                    'Calls' => [
                        ['Calls', number_format($counts['calls_made'] + $counts['calls_received']), 'phone', 'calls'],
                        ['Talk time', gmdate($counts['call_seconds'] >= 3600 ? 'G\h i\m' : 'i:s', $counts['call_seconds']), 'clock', 'calls'],
                        ['Missed', number_format($counts['calls_missed']), 'phone-missed', 'calls'],
                    ],
                    'People & devices' => [
                        ['Contacts saved', number_format($counts['contacts']), 'contact', 'contacts'],
                        ['They blocked', number_format($counts['blocked']), 'ban', 'contacts'],
                        ['Blocked by others', number_format($counts['blocked_by']), 'shield', 'contacts'],
                        ['Phones (app)', number_format($counts['devices']), 'smartphone', 'devices'],
                        ['Sign-ins', number_format($counts['logins']), 'log-in', 'devices'],
                    ],
                ] as $group => $rows)
                    <div class="admin-numbers-group">
                        <h3 class="admin-numbers-title">{{ $group }}</h3>
                        @foreach ($rows as [$label, $value, $icon, $target])
                            <{{ $target ? 'a' : 'div' }} @if ($target) href="{{ route('admin.users.show', ['user' => $user, 'tab' => $target]) }}" @endif class="admin-number">
                                <x-icon :name="$icon" />
                                <span class="admin-number-label">{{ $label }}</span>
                                <span class="admin-number-value">{{ $value }}</span>
                            </{{ $target ? 'a' : 'div' }}>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Messages sent, last 30 days</h2>
                    <p class="card-subtitle">{{ number_format(collect($days)->sum('count')) }} messages · <a class="admin-link" href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'activity']) }}">More activity</a></p>
                </div>
            </div>
            <div class="card-body">
                <x-admin.bars :series="collect($days)->map(fn ($d) => ['label' => \Illuminate\Support\Carbon::parse($d['date'])->format('j'), 'title' => \Illuminate\Support\Carbon::parse($d['date'])->format('D j M'), 'count' => $d['count']])->all()" label="Messages sent per day for the last 30 days" />
            </div>
        </section>

        @if ($canManage)
            @unless ($user->isBanned())
                <section class="card admin-tool" id="ban">
                    <div class="card-body">
                        <h3 class="admin-section-title is-danger"><x-icon name="shield-ban" /> Ban {{ $user->name }}</h3>
                        <p class="admin-muted">They're signed out everywhere right away and see a ban screen with your reason when they try to open the app.</p>
                        <form method="POST" action="{{ route('admin.users.ban', $user) }}" class="admin-form" data-confirm="{{ $user->name }} will be signed out and can't use the app until the ban ends." data-confirm-title="Ban {{ $user->name }}?" data-confirm-label="Ban" data-confirm-danger>
                            @csrf
                            <div class="form-group">
                                <span class="form-label">How long</span>
                                <div class="admin-chips" role="radiogroup" aria-label="Ban length">
                                    @foreach (\App\Services\BanService::DURATIONS as $value => $label)
                                        <label class="admin-chip">
                                            <input type="radio" name="duration" value="{{ $value }}" @checked(old('duration', '7') === (string) $value)>
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('duration', 'ban')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="form-group">
                                <label for="ban-reason" class="form-label">Reason <span class="optional">(shown to {{ $user->name }})</span></label>
                                <textarea id="ban-reason" name="reason" class="form-control @error('reason', 'ban') is-invalid @enderror" rows="3" maxlength="500" placeholder="e.g. Sending spam to many people" required>{{ old('reason') }}</textarea>
                                @error('reason', 'ban')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div><button type="submit" class="btn btn-danger"><x-icon name="shield-ban" /> Ban account</button></div>
                        </form>
                    </div>
                </section>
            @endunless

            <section class="card admin-tool" id="edit">
                <div class="card-body">
                    <details class="admin-disclosure" @if ($errors->getBag('edit')->isNotEmpty()) open @endif>
                        <summary class="admin-section-title"><x-icon name="user-pen" /> Edit profile <x-icon name="chevron-down" class="admin-disclosure-chevron" /></summary>
                        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="admin-form" data-loading-form novalidate>
                            @csrf
                            @method('PUT')
                            <div class="auth-grid auth-grid-2">
                                <x-field name="name" id="edit-name" label="Name" icon="user" :value="$user->name" bag="edit" maxlength="100" required />
                                <x-field name="username" id="edit-username" label="Username" icon="at-sign" :value="$user->username" bag="edit" maxlength="30" required />
                                <x-field name="email" id="edit-email" type="email" label="Email" icon="mail" :value="$user->email" bag="edit" maxlength="191" optional />
                                <x-field name="phone" id="edit-phone" type="tel" label="Mobile" icon="phone" :value="$user->phone" bag="edit" maxlength="20" required />
                            </div>
                            <x-field name="about" id="edit-about" label="About" icon="info" :value="$user->about" bag="edit" maxlength="139" optional />
                            <div><button type="submit" class="btn btn-primary"><x-icon name="check" /> Save changes</button></div>
                        </form>
                    </details>
                </div>
            </section>
        @endif

        <section class="card admin-tool">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="sliders-horizontal" /> More actions</h3>
                <div class="admin-actions-list">
                    <a href="{{ route('admin.users.data', $user) }}" class="admin-action-row"><x-icon name="file-json" /> <span>Download all account data (JSON)</span></a>
                    @if ($canRole)
                        <form method="POST" action="{{ route('admin.users.role', $user) }}" data-confirm="{{ $user->isAdmin() ? $user->name.' will lose access to the admin panel.' : $user->name.' will be able to open the admin panel, read chats and ban people.' }}" data-confirm-title="{{ $user->isAdmin() ? 'Remove administrator?' : 'Make administrator?' }}" data-confirm-label="{{ $user->isAdmin() ? 'Remove' : 'Make admin' }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="role" value="{{ $user->isAdmin() ? 'user' : 'admin' }}">
                            <button type="submit" class="admin-action-row"><x-icon name="crown" /> <span>{{ $user->isAdmin() ? 'Remove administrator role' : 'Make administrator' }}</span></button>
                        </form>
                    @endif
                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.users.logout', $user) }}" data-confirm="{{ $user->name }} will be signed out of every browser and phone." data-confirm-title="Sign out everywhere?" data-confirm-label="Sign out">
                            @csrf
                            <button type="submit" class="admin-action-row"><x-icon name="log-out" /> <span>Sign out of every device</span></button>
                        </form>
                        @if ($user->profile_image)
                            <form method="POST" action="{{ route('admin.users.photo', $user) }}" data-confirm="The profile photo will be removed." data-confirm-title="Remove profile photo?" data-confirm-label="Remove">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-action-row"><x-icon name="image-off" /> <span>Remove profile photo</span></button>
                            </form>
                        @endif
                        @if ($twoStep)
                            <form method="POST" action="{{ route('admin.users.two-step', $user) }}" data-confirm="Two-step verification will be turned off for {{ $user->name }}." data-confirm-title="Turn off two-step verification?" data-confirm-label="Turn off">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="admin-action-row"><x-icon name="shield-off" /> <span>Turn off two-step verification</span></button>
                            </form>
                        @endif
                    @endif
                    <a href="{{ route('admin.statuses', ['user' => $user->id]) }}" class="admin-action-row"><x-icon name="circle-dashed" /> <span>Status updates</span></a>
                    <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'history']) }}" class="admin-action-row"><x-icon name="scroll-text" /> <span>Admin history for this account</span></a>
                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}" data-confirm="This permanently deletes the account, its chats, messages and files. Groups of other people stay. This cannot be undone." data-confirm-title="Delete {{ $user->name }}?" data-confirm-label="Delete permanently" data-confirm-danger>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="admin-action-row is-danger"><x-icon name="trash-2" /> <span>Delete account</span></button>
                        </form>
                    @endif
                </div>
            </div>
        </section>

        @if ($activity->isNotEmpty())
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon name="history" /> Admin history</h3>
                    @foreach ($activity as $log)
                        <div class="admin-list-row is-compact">
                            <span class="admin-list-body">
                                <span class="admin-list-title">{{ $log->description }}</span>
                                <span class="admin-list-text">{{ $log->admin?->name ?? 'Deleted admin' }} · {{ $log->created_at->format('j M Y, g:i A') }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
