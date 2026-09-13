@php
    $cards = [
        ['label' => 'Total users', 'value' => $stats['total_users'], 'icon' => 'users', 'tone' => 'primary', 'hint' => $stats['new_users'].' new this week'],
        ['label' => 'Online now', 'value' => $stats['online_users'], 'icon' => 'activity', 'tone' => 'success', 'hint' => 'Active in the last '.intdiv(config('chat.online_threshold_seconds'), 60).' min'],
        ['label' => 'Conversations', 'value' => $stats['total_conversations'], 'icon' => 'message-circle', 'tone' => 'violet', 'hint' => 'One-to-one chats'],
        ['label' => 'Messages', 'value' => $stats['total_messages'], 'icon' => 'message-square-text', 'tone' => 'sky', 'hint' => $stats['messages_today'].' sent today'],
        ['label' => 'New users', 'value' => $stats['new_users'], 'icon' => 'user-plus', 'tone' => 'amber', 'hint' => 'Joined in the last 7 days'],
        ['label' => 'Blocked users', 'value' => $stats['blocked_users'], 'icon' => 'ban', 'tone' => 'danger', 'hint' => $stats['block_relations'].' block '.str('relation')->plural($stats['block_relations'])],
    ];
    $maxVolume = max(1, collect($volume)->max('count'));
@endphp

<x-layouts.admin title="Dashboard" heading="Dashboard" subheading="Overview of accounts and platform activity.">
    <div class="stat-grid">
        @foreach ($cards as $card)
            <div class="stat-card" data-tone="{{ $card['tone'] }}">
                <div class="stat-icon"><x-icon :name="$card['icon']" /></div>
                <div class="min-w-0">
                    <div class="stat-label">{{ $card['label'] }}</div>
                    <div class="stat-value">{{ number_format($card['value']) }}</div>
                    <div class="stat-hint">{{ $card['hint'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="admin-grid">
        <section class="card">
            <div class="card-header flex items-center justify-between gap-3">
                <div>
                    <h2 class="card-title">Message volume</h2>
                    <p class="card-subtitle">Messages sent per day (counts only).</p>
                </div>
                <x-icon name="trending-up" class="text-subtle" />
            </div>
            <div class="card-body">
                <div class="volume-chart" role="img" aria-label="Messages per day for the last 7 days">
                    @foreach ($volume as $day)
                        <div class="volume-col" title="{{ $day['date'] }}: {{ $day['count'] }} messages">
                            <span class="volume-count">{{ $day['count'] }}</span>
                            <span class="volume-bar" style="--h: {{ max(2, round($day['count'] / $maxVolume * 100)) }}%"></span>
                            <span class="volume-label">{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header flex items-center justify-between gap-3">
                <div>
                    <h2 class="card-title">Account health</h2>
                    <p class="card-subtitle">Status of all user accounts.</p>
                </div>
            </div>
            <div class="card-body flex flex-col gap-4">
                @php
                    $active = $stats['total_users'] - $stats['suspended_users'] - $stats['inactive_users'];
                    $rows = [
                        ['Active', $active, 'success'],
                        ['Inactive', $stats['inactive_users'], 'warning'],
                        ['Suspended', $stats['suspended_users'], 'danger'],
                    ];
                @endphp
                @foreach ($rows as [$label, $count, $tone])
                    <a href="{{ route('admin.users', ['status' => strtolower($label)]) }}" class="health-row">
                        <span class="flex items-center justify-between text-sm">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-muted">{{ number_format($count) }}</span>
                        </span>
                        <span class="health-track"><span class="health-fill" data-tone="{{ $tone }}" style="width: {{ $stats['total_users'] ? round($count / $stats['total_users'] * 100) : 0 }}%"></span></span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    <section class="card mt-5">
        <div class="card-header flex items-center justify-between gap-3">
            <div>
                <h2 class="card-title">Newest users</h2>
                <p class="card-subtitle">Recently registered accounts.</p>
            </div>
            <a href="{{ route('admin.users') }}" class="btn btn-secondary btn-sm">View all</a>
        </div>
        <div class="card-body p-0!">
            @foreach ($recentUsers as $recent)
                <a href="{{ route('admin.users.show', $recent) }}" class="admin-list-row">
                    <x-avatar :user="$recent" size="md" status />
                    <span class="flex-1 min-w-0">
                        <span class="block font-semibold truncate">{{ $recent->name }}</span>
                        <span class="block text-sm text-muted truncate">{{ '@'.$recent->username }} · {{ $recent->email }}</span>
                    </span>
                    <x-admin.status-badge :user="$recent" />
                    <span class="text-xs text-subtle hidden sm:inline">{{ $recent->created_at->diffForHumans() }}</span>
                </a>
            @endforeach
        </div>
    </section>
</x-layouts.admin>
