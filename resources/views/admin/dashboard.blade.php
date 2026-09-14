@php
    $cards = [
        ['label' => 'Users', 'value' => $stats['total_users'], 'icon' => 'users', 'tone' => 'primary', 'hint' => $stats['new_users'].' new this week', 'href' => route('admin.users')],
        ['label' => 'Online now', 'value' => $stats['online_users'], 'icon' => 'activity', 'tone' => 'success', 'hint' => 'Active in the last '.intdiv(config('chat.online_threshold_seconds'), 60).' min', 'href' => route('admin.users', ['online' => 1])],
        ['label' => 'Messages', 'value' => $stats['total_messages'], 'icon' => 'message-square-text', 'tone' => 'sky', 'hint' => number_format($stats['messages_today']).' sent today', 'href' => route('admin.messages')],
        ['label' => 'Chats', 'value' => $stats['total_conversations'], 'icon' => 'message-circle', 'tone' => 'violet', 'hint' => 'All chats, groups and channels', 'href' => route('admin.chats')],
        ['label' => 'Groups', 'value' => $stats['groups'], 'icon' => 'users-round', 'tone' => 'primary', 'hint' => 'Groups still open', 'href' => route('admin.groups')],
        ['label' => 'Channels', 'value' => $stats['channels'], 'icon' => 'rss', 'tone' => 'sky', 'hint' => 'Public channels', 'href' => route('admin.channels')],
        ['label' => 'Communities', 'value' => $stats['communities'], 'icon' => 'layers', 'tone' => 'violet', 'hint' => 'With their groups', 'href' => route('admin.communities')],
        ['label' => 'Status updates', 'value' => $stats['statuses'], 'icon' => 'circle-dashed', 'tone' => 'amber', 'hint' => 'Live right now', 'href' => route('admin.statuses')],
        ['label' => 'Calls today', 'value' => $stats['calls_today'], 'icon' => 'phone', 'tone' => 'success', 'hint' => 'Voice and video', 'href' => null],
        ['label' => 'Open reports', 'value' => $stats['open_reports'], 'icon' => 'message-square-warning', 'tone' => 'danger', 'hint' => 'Waiting for review', 'href' => route('admin.reports')],
        ['label' => 'Banned', 'value' => $stats['banned_users'], 'icon' => 'shield-ban', 'tone' => 'danger', 'hint' => $stats['suspended_users'].' suspended', 'href' => route('admin.users', ['status' => 'banned'])],
        ['label' => 'Blocked users', 'value' => $stats['blocked_users'], 'icon' => 'ban', 'tone' => 'amber', 'hint' => $stats['block_relations'].' '.str('block')->plural($stats['block_relations']).' between users', 'href' => null],
    ];
    $maxVolume = max(1, collect($volume)->max('count'));
    $totalVolume = collect($volume)->sum('count');
@endphp

<x-layouts.admin title="Dashboard" heading="Dashboard" :subheading="'Everything happening in '.config('app.name').'.'">
    <div class="stat-grid">
        @foreach ($cards as $card)
            <{{ $card['href'] ? 'a' : 'div' }} @if ($card['href']) href="{{ $card['href'] }}" @endif class="stat-card" data-tone="{{ $card['tone'] }}">
                <span class="stat-icon"><x-icon :name="$card['icon']" /></span>
                <span class="min-w-0">
                    <span class="stat-label">{{ $card['label'] }}</span>
                    <span class="stat-value">{{ number_format($card['value']) }}</span>
                    <span class="stat-hint">{{ $card['hint'] }}</span>
                </span>
            </{{ $card['href'] ? 'a' : 'div' }}>
        @endforeach
    </div>

    <div class="admin-grid">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Messages, last 14 days</h2>
                    <p class="card-subtitle">{{ number_format($totalVolume) }} messages · busiest day {{ number_format($maxVolume) }}</p>
                </div>
                <x-icon name="trending-up" class="text-subtle" />
            </div>
            <div class="card-body">
                <div class="volume-chart" style="--days: {{ count($volume) }}" role="img" aria-label="Messages per day for the last {{ count($volume) }} days">
                    @foreach ($volume as $day)
                        <div class="volume-col" title="{{ $day['date'] }}: {{ $day['count'] }} messages">
                            <span class="volume-count">{{ $day['count'] ?: '' }}</span>
                            <span class="volume-bar" style="--h: {{ max(2, round($day['count'] / $maxVolume * 100)) }}%"></span>
                            <span class="volume-label">{{ $loop->last ? 'Today' : \Illuminate\Support\Carbon::parse($day['date'])->format('j') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Accounts</h2>
                    <p class="card-subtitle">Status of all accounts.</p>
                </div>
            </div>
            <div class="card-body flex flex-col gap-4">
                @php
                    $active = $stats['total_users'] - $stats['suspended_users'] - $stats['inactive_users'] - $stats['banned_users'];
                    $rows = [
                        ['Active', 'active', $active, 'success'],
                        ['Inactive', 'inactive', $stats['inactive_users'], 'warning'],
                        ['Suspended', 'suspended', $stats['suspended_users'], 'danger'],
                        ['Banned', 'banned', $stats['banned_users'], 'danger'],
                    ];
                @endphp
                @foreach ($rows as [$label, $status, $count, $tone])
                    <a href="{{ route('admin.users', ['status' => $status]) }}" class="health-row">
                        <span class="flex items-center justify-between text-sm">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-muted tabular-nums">{{ number_format($count) }}</span>
                        </span>
                        <span class="health-track"><span class="health-fill" data-tone="{{ $tone }}" style="width: {{ $stats['total_users'] ? round($count / $stats['total_users'] * 100) : 0 }}%"></span></span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    <div class="admin-grid admin-grid-even">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Newest users</h2>
                    <p class="card-subtitle">Recently registered accounts.</p>
                </div>
                <a href="{{ route('admin.users') }}" class="btn btn-secondary btn-sm">All users</a>
            </div>
            <div class="admin-list">
                @foreach ($recentUsers as $recent)
                    <a href="{{ route('admin.users.show', $recent) }}" class="admin-list-row">
                        <x-avatar :user="$recent" size="md" status />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $recent->name }}</span>
                            <span class="admin-list-text">{{ '@'.$recent->username }} · {{ $recent->created_at->diffForHumans() }}</span>
                        </span>
                        <x-admin.status-badge :user="$recent" />
                    </a>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Admin activity</h2>
                    <p class="card-subtitle">Latest entries in the audit log.</p>
                </div>
                <a href="{{ route('admin.audit') }}" class="btn btn-secondary btn-sm">Audit log</a>
            </div>
            <div class="admin-list">
                @forelse ($recentActivity as $log)
                    <div class="admin-list-row">
                        <span class="admin-log-icon" data-action="{{ \Illuminate\Support\Str::before($log->action, '.') }}"><x-icon name="scroll-text" /></span>
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $log->description }}</span>
                            <span class="admin-list-text">{{ $log->admin?->name ?? 'Deleted admin' }} · {{ $log->created_at->diffForHumans() }}</span>
                        </span>
                    </div>
                @empty
                    <div class="empty-state">
                        <div class="empty-state-icon"><x-icon name="scroll-text" /></div>
                        <div class="empty-state-title">Nothing yet</div>
                        <div class="empty-state-text">Bans, deletions and opened chats will show up here.</div>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.admin>
