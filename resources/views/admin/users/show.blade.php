<x-layouts.admin :title="$user->name" :heading="$user->name" :subheading="'@'.$user->username.' · joined '.$user->created_at->format('j M Y')" :back="route('admin.users')">
    @php
        $canManage = auth()->user()->can('manage', $user);
        $canRole = auth()->user()->can('changeRole', $user);
    @endphp

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
                    <a href="{{ route('admin.chats', ['user' => $user->id]) }}" class="btn btn-primary btn-sm"><x-icon name="message-circle" /> View chats</a>
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
                        <div><dt><x-icon name="mail" /> Email</dt><dd>{{ $user->email }} @if ($user->email_verified_at)<span class="badge badge-success">Verified</span>@endif</dd></div>
                        <div><dt><x-icon name="phone" /> Mobile</dt><dd class="tabular-nums">{{ $user->phone }} @if ($user->phone_verified_at)<span class="badge badge-success">SMS</span>@endif</dd></div>
                        <div><dt><x-icon name="clock" /> Last seen</dt><dd>{{ $user->isOnlineNow() ? 'Online now' : ($user->last_seen?->format('j M Y, g:i A') ?? 'Never') }}</dd></div>
                        <div><dt><x-icon name="user-plus" /> Joined</dt><dd>{{ $user->created_at->format('j M Y, g:i A') }}</dd></div>
                        <div><dt><x-icon name="shield-check" /> Two-step</dt><dd>{{ $twoStep ? 'On' : 'Off' }}</dd></div>
                        <div><dt><x-icon name="lock" /> Privacy</dt><dd>Last seen: {{ $user->last_seen_privacy }} · Photo: {{ $user->photo_privacy }}</dd></div>
                        <div><dt><x-icon name="message-square-warning" /> Reports</dt><dd>{{ $reportsAbout }} about this person</dd></div>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title">Signed in on</h3>
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

        {{-- ======================== Right: activity and tools ======================== --}}
        <div class="admin-stack">
            <section class="card">
                <div class="card-body">
                    <div class="mini-stats">
                        @foreach ([
                            ['Messages sent', $counts['messages_sent'], 'send-horizontal'],
                            ['Messages received', $summary['messages_received'], 'message-square-text'],
                            ['Chats', $counts['chats'], 'message-circle'],
                            ['Groups', $counts['groups'], 'users-round'],
                            ['Communities', $counts['communities'], 'layers'],
                            ['Channels', $counts['channels'], 'rss'],
                            ['They blocked', $summary['blocked_by_user'], 'ban'],
                            ['Blocked by others', $summary['blocked_by_others'], 'shield'],
                        ] as [$label, $value, $icon])
                            <div class="mini-stat">
                                <x-icon :name="$icon" class="text-primary" />
                                <span class="mini-stat-value">{{ number_format($value) }}</span>
                                <span class="mini-stat-label">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header admin-card-head">
                    <div>
                        <h2 class="card-title">Chats</h2>
                        <p class="card-subtitle">Opening a chat is recorded in the audit log.</p>
                    </div>
                    <a href="{{ route('admin.chats', ['user' => $user->id]) }}" class="btn btn-secondary btn-sm">All</a>
                </div>
                <div class="admin-list">
                    @forelse ($chats as $chat)
                        @php($other = $chat->hasMembers() ? null : ((int) $chat->user_one_id === (int) $user->id ? $chat->userTwo : $chat->userOne))
                        <a href="{{ route('admin.chats.show', $chat) }}" class="admin-list-row">
                            @if ($other)
                                <x-avatar :user="$other" size="md" />
                            @else
                                <x-admin.space-avatar :name="$content->title($chat)" :icon="$chat->isChannel() ? 'rss' : ($chat->isBroadcast() ? 'megaphone' : null)" />
                            @endif
                            <span class="admin-list-body">
                                <span class="admin-list-title">{{ $other ? $other->name : $content->title($chat) }}</span>
                                <span class="admin-list-text">{{ $content->typeLabel($chat) }} · {{ number_format($chat->messages_count) }} messages · {{ $chat->updated_at?->diffForHumans() }}</span>
                            </span>
                            <x-icon name="chevron-right" class="text-subtle" />
                        </a>
                    @empty
                        <p class="admin-muted p-5">No chats yet.</p>
                    @endforelse
                </div>
            </section>

            @if ($canManage)
                {{-- Ban --}}
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

                {{-- Edit --}}
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

            @if ($canManage || $canRole)
                <section class="card admin-tool">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="sliders-horizontal" /> More actions</h3>
                        <div class="admin-actions-list">
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
                                <a href="{{ route('admin.statuses', ['user' => $user->id]) }}" class="admin-action-row"><x-icon name="circle-dashed" /> <span>Status updates</span></a>
                                <a href="{{ route('admin.audit', ['target' => $user->id]) }}" class="admin-action-row"><x-icon name="scroll-text" /> <span>Admin history for this account</span></a>
                                <form method="POST" action="{{ route('admin.users.destroy', $user) }}" data-confirm="This permanently deletes the account, its chats, messages and files. Groups of other people stay. This cannot be undone." data-confirm-title="Delete {{ $user->name }}?" data-confirm-label="Delete permanently" data-confirm-danger>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="admin-action-row is-danger"><x-icon name="trash-2" /> <span>Delete account</span></button>
                                </form>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

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
</x-layouts.admin>
