@php
    $eventBadge = ['login' => ['badge-success', 'Signed in'], 'failed' => ['badge-danger', 'Wrong password'], 'logout' => ['badge-muted', 'Signed out']];
@endphp
<div class="admin-stack">
    <div class="admin-grid admin-grid-even">
        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="monitor" /> Browsers signed in now</h3>
                @forelse ($sessions as $session)
                    <div class="admin-list-row is-compact">
                        <span class="session-icon"><x-icon :name="$session['mobile'] ? 'smartphone' : 'monitor'" /></span>
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $session['device'] }}{{ $session['app'] ? ' · Android app' : '' }}</span>
                            <span class="admin-list-text">{{ $session['ip'] ?? 'Unknown network' }} · active {{ \Illuminate\Support\Carbon::parse($session['last_active'])->diffForHumans() }}</span>
                        </span>
                        @can('manage', $user)
                            <form method="POST" action="{{ route('admin.users.sessions.destroy', ['user' => $user, 'key' => $session['key']]) }}" data-confirm="{{ $user->name }} will be signed out of this browser." data-confirm-title="Sign out this browser?" data-confirm-label="Sign out">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-secondary btn-sm">Sign out</button>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="admin-muted">Not signed in on any browser.</p>
                @endforelse
            </div>
        </section>

        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="smartphone" /> Phones with the app</h3>
                @forelse ($phones as $phone)
                    <div class="admin-list-row is-compact">
                        <span class="session-icon"><x-icon name="smartphone" /></span>
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ ucfirst($phone->platform) }}{{ $phone->app_version ? ' · app '.$phone->app_version : '' }}</span>
                            <span class="admin-list-text">
                                {{ $phone->fcm_token ? 'Notifications on' : 'No push token' }} · added {{ $phone->created_at?->format('j M Y') }} · used {{ $phone->last_used_at?->diffForHumans() ?? 'never' }}
                            </span>
                        </span>
                    </div>
                @empty
                    <p class="admin-muted">No phone has the app signed in.</p>
                @endforelse

                @if ($trusted->isNotEmpty())
                    <h3 class="admin-section-title mt-4"><x-icon name="shield-check" /> Trusted for two-step</h3>
                    @foreach ($trusted as $device)
                        <div class="admin-list-row is-compact">
                            <span class="admin-list-body">
                                <span class="admin-list-title">{{ $device->name ?: 'Browser' }}</span>
                                <span class="admin-list-text">{{ $device->ip_address ?? '—' }} · used {{ $device->last_used_at?->diffForHumans() ?? 'never' }}</span>
                            </span>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Sign-in history</h2>
                <p class="card-subtitle">{{ number_format($networks) }} different networks. Kept {{ \App\Models\UserLogin::KEEP_DAYS }} days.</p>
            </div>
            <nav class="admin-tabs" aria-label="Kind of event">
                @foreach ([null => 'All', 'login' => 'Sign-ins', 'failed' => 'Wrong passwords', 'logout' => 'Sign-outs'] as $value => $label)
                    <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'devices', 'event' => $value ?: null]) }}" @class(['admin-tab', 'is-active' => $loginEvent == $value])>{{ $label }}</a>
                @endforeach
            </nav>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>What</th>
                        <th class="hidden md:table-cell">Device</th>
                        <th class="hidden md:table-cell">Network</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logins as $login)
                        @php([$badge, $label] = $eventBadge[$login->event] ?? ['badge-muted', $login->event])
                        <tr>
                            <td class="whitespace-nowrap text-sm">
                                <span class="block">{{ $login->created_at->format('j M Y') }}</span>
                                <span class="block text-xs text-muted">{{ $login->created_at->format('g:i:s A') }}</span>
                            </td>
                            <td>
                                <span class="badge {{ $badge }}">{{ $label }}</span>
                                @if ($login->method && $login->event !== 'logout')
                                    <span class="block text-xs text-muted mt-1">{{ $login->methodLabel() }}</span>
                                @endif
                            </td>
                            <td class="hidden md:table-cell text-sm">{{ \App\Support\UserAgent::describe($login->user_agent) }}</td>
                            <td class="hidden md:table-cell text-xs text-muted tabular-nums">{{ $login->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><div class="empty-state-icon"><x-icon name="log-in" /></div><div class="empty-state-title">Nothing recorded yet</div><div class="empty-state-text">Sign-ins are recorded from now on.</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$logins" label="events" />
    </section>

    @if ($shared->isNotEmpty())
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Other accounts on the same networks</h2>
                    <p class="card-subtitle">Accounts that signed in from the same internet address. Families and offices share networks, so this alone proves nothing.</p>
                </div>
            </div>
            <div class="admin-list">
                @foreach ($shared as $row)
                    <a href="{{ route('admin.users.show', $row['user']) }}" class="admin-list-row">
                        <x-avatar :user="$row['user']" size="md" status />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $row['user']->name }}</span>
                            <span class="admin-list-text">{{ '@'.$row['user']->username }} · {{ $row['networks'] }} shared {{ str('network')->plural($row['networks']) }} · last {{ $row['last_at']->diffForHumans() }}</span>
                        </span>
                        <x-admin.status-badge :user="$row['user']" />
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
