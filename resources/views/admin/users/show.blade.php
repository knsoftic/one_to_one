<x-layouts.admin :title="$user->name" heading="User details" subheading="Account information and activity counts.">
    <a href="{{ route('admin.users') }}" class="inline-flex items-center gap-1 text-sm text-muted mb-4 hover:text-ink"><x-icon name="arrow-left" class="icon-sm" /> All users</a>

    <div class="admin-grid">
        <section class="card">
            <div class="card-body flex flex-col items-center text-center gap-2">
                <x-avatar :user="$user" size="2xl" status />
                <h2 class="text-xl font-bold mt-2">{{ $user->name }}</h2>
                <p class="text-muted">{{ '@'.$user->username }}</p>
                <div class="flex flex-wrap justify-center gap-1.5"><x-admin.status-badge :user="$user" /></div>
                <div class="mt-3"><x-admin.user-actions :user="$user" /></div>
            </div>
            <div class="card-footer justify-start! flex-col items-stretch! gap-0!">
                @foreach ([
                    ['mail', 'Email', $user->email],
                    ['phone', 'Mobile', $user->phone],
                    ['clock', 'Last seen', $user->isOnlineNow() ? 'Online now' : ($user->last_seen?->format('M j, Y g:i A') ?? 'Never')],
                    ['user-plus', 'Joined', $user->created_at->format('M j, Y g:i A')],
                ] as [$icon, $label, $value])
                    <div class="detail-row">
                        <span class="flex items-center gap-2 text-muted text-sm"><x-icon :name="$icon" class="icon-sm" /> {{ $label }}</span>
                        <span class="text-sm font-medium text-right break-all">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Activity</h2>
                <p class="card-subtitle">Aggregate counts only — message content stays private.</p>
            </div>
            <div class="card-body">
                <div class="mini-stats">
                    @foreach ([
                        ['Messages sent', $summary['messages_sent'], 'send-horizontal'],
                        ['Messages received', $summary['messages_received'], 'message-square-text'],
                        ['Conversations', $summary['conversations'], 'message-circle'],
                        ['Users they blocked', $summary['blocked_by_user'], 'ban'],
                        ['Blocked by others', $summary['blocked_by_others'], 'shield'],
                    ] as [$label, $value, $icon])
                        <div class="mini-stat">
                            <x-icon :name="$icon" class="text-primary" />
                            <span class="mini-stat-value">{{ number_format($value) }}</span>
                            <span class="mini-stat-label">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>

                <div class="alert alert-info mt-5">
                    <x-icon name="lock" />
                    <span>For privacy, administrators cannot read private conversations. Moderation relies on account status controls.</span>
                </div>
            </div>
        </section>
    </div>
</x-layouts.admin>
