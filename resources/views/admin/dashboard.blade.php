@php
    $cards = [
        ['label' => 'Users', 'value' => $stats['total_users'], 'icon' => 'users', 'tone' => 'primary', 'hint' => number_format($stats['new_users']).' new this week', 'href' => route('admin.users')],
        ['label' => 'Online now', 'value' => $stats['online_users'], 'icon' => 'activity', 'tone' => 'success', 'hint' => number_format($insights['active_30d']).' active in 30 days', 'href' => route('admin.users', ['online' => 1])],
        ['label' => 'Messages', 'value' => $stats['total_messages'], 'icon' => 'message-square-text', 'tone' => 'sky', 'hint' => number_format($stats['messages_today']).' sent today', 'href' => route('admin.messages')],
        ['label' => 'Chats', 'value' => $stats['total_conversations'], 'icon' => 'message-circle', 'tone' => 'violet', 'hint' => 'All chats, groups and channels', 'href' => route('admin.chats')],
        ['label' => 'Groups', 'value' => $stats['groups'], 'icon' => 'users-round', 'tone' => 'primary', 'hint' => 'Groups still open', 'href' => route('admin.groups')],
        ['label' => 'Channels', 'value' => $stats['channels'], 'icon' => 'rss', 'tone' => 'sky', 'hint' => 'Public channels', 'href' => route('admin.channels')],
        ['label' => 'Communities', 'value' => $stats['communities'], 'icon' => 'layers', 'tone' => 'violet', 'hint' => 'With their groups', 'href' => route('admin.communities')],
        ['label' => 'Status updates', 'value' => $stats['statuses'], 'icon' => 'circle-dashed', 'tone' => 'amber', 'hint' => 'Live right now', 'href' => route('admin.statuses')],
        ['label' => 'Calls today', 'value' => $stats['calls_today'], 'icon' => 'phone', 'tone' => 'success', 'hint' => 'Voice and video', 'href' => null],
        ['label' => 'Open reports', 'value' => $stats['open_reports'], 'icon' => 'message-square-warning', 'tone' => 'danger', 'hint' => 'Waiting for review', 'href' => route('admin.reports')],
        ['label' => 'Banned', 'value' => $stats['banned_users'], 'icon' => 'shield-ban', 'tone' => 'danger', 'hint' => number_format($stats['suspended_users']).' suspended', 'href' => route('admin.users', ['status' => 'banned'])],
        ['label' => 'Sign-ins today', 'value' => $insights['logins']['today'], 'icon' => 'log-in', 'tone' => 'amber', 'hint' => number_format($insights['logins']['failed_today']).' wrong passwords', 'href' => null],
    ];
    $days = $insights['days'];
    $series = fn (string $key) => collect($insights['series'][$key])->map(fn ($d) => [
        'label' => $days > 31 ? \Illuminate\Support\Carbon::parse($d['date'])->format('j M') : \Illuminate\Support\Carbon::parse($d['date'])->format('j'),
        'title' => \Illuminate\Support\Carbon::parse($d['date'])->format('D j M'),
        'count' => $d['count'],
    ])->all();
    $sum = fn (string $key) => collect($insights['series'][$key])->sum('count');
    $bytes = function (?int $value): string {
        if (! $value) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = min((int) floor(log($value, 1024)), count($units) - 1);

        return round($value / (1024 ** $i), $i === 0 ? 0 : 1).' '.$units[$i];
    };
    $typeLabels = ['text' => 'Text', 'image' => 'Photos & GIFs', 'video' => 'Videos', 'voice' => 'Voice', 'document' => 'Documents', 'sticker' => 'Stickers', 'location' => 'Locations', 'contact' => 'Contacts', 'poll' => 'Polls', 'call' => 'Calls'];
    $typeTotal = max(1, array_sum($insights['types']));
    $generated = \Illuminate\Support\Carbon::parse($insights['generated_at']);
@endphp

<x-layouts.admin title="Dashboard" heading="Dashboard" :subheading="'Everything happening in '.config('app.name').'.'">
    <div class="admin-dash-toolbar">
        <nav class="admin-tabs" aria-label="Period">
            @foreach (\App\Services\AdminInsightsService::RANGES as $range)
                <a href="{{ route('admin.dashboard', ['days' => $range]) }}" @class(['admin-tab', 'is-active' => $days === $range])>Last {{ $range }} days</a>
            @endforeach
        </nav>
        <span class="admin-dash-updated">
            Updated {{ $generated->diffForHumans() }}
            <a href="{{ route('admin.dashboard', ['days' => $days, 'refresh' => 1]) }}" class="btn btn-ghost btn-sm"><x-icon name="refresh-cw" /> Refresh</a>
        </span>
    </div>

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

    <div class="admin-grid admin-grid-even">
        @foreach ([
            ['messages', 'Messages', 'messages', 'message-square-text'],
            ['active', 'People sending messages', 'people', 'activity'],
            ['users', 'New accounts', 'accounts', 'user-plus'],
            ['calls', 'Calls', 'calls', 'phone'],
        ] as [$key, $title, $unit, $icon])
            <section class="card">
                <div class="card-header admin-card-head">
                    <div>
                        <h2 class="card-title">{{ $title }}</h2>
                        <p class="card-subtitle">
                            @if ($key === 'active')
                                Busiest day {{ number_format(collect($insights['series'][$key])->max('count')) }} people
                            @else
                                {{ number_format($sum($key)) }} in {{ $days }} days · busiest day {{ number_format(collect($insights['series'][$key])->max('count')) }}
                            @endif
                        </p>
                    </div>
                    <x-icon :name="$icon" class="text-subtle" />
                </div>
                <div class="card-body">
                    <x-admin.bars :series="$series($key)" :label="$title.' per day for the last '.$days.' days'" :unit="$unit" height="10rem" />
                </div>
            </section>
        @endforeach
    </div>

    <div class="admin-grid">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Most active people</h2>
                    <p class="card-subtitle">Messages sent in the last {{ $days }} days.</p>
                </div>
            </div>
            <div class="admin-list">
                @forelse ($insights['top_users'] as $i => $row)
                    <a href="{{ route('admin.users.show', $row['user']) }}" class="admin-list-row">
                        <span class="admin-rank tabular-nums">{{ $i + 1 }}</span>
                        <x-avatar :user="$row['user']" size="md" status />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $row['user']->name }}</span>
                            <span class="admin-list-text">{{ '@'.$row['user']->username }}</span>
                        </span>
                        <span class="admin-list-value tabular-nums">{{ number_format($row['messages']) }}</span>
                    </a>
                @empty
                    <p class="admin-muted p-5">No messages in this period.</p>
                @endforelse
            </div>
        </section>

        <div class="admin-stack">
            <section class="card">
                <div class="card-header admin-card-head">
                    <div>
                        <h2 class="card-title">What people send</h2>
                        <p class="card-subtitle">Last {{ $days }} days.</p>
                    </div>
                </div>
                <div class="card-body flex flex-col gap-3">
                    @forelse ($insights['types'] as $type => $count)
                        <div class="health-row">
                            <span class="flex items-center justify-between text-sm">
                                <span class="font-semibold">{{ $typeLabels[$type] ?? ucfirst($type) }}</span>
                                <span class="text-muted tabular-nums">{{ number_format($count) }} · {{ round($count / $typeTotal * 100) }}%</span>
                            </span>
                            <span class="health-track"><span class="health-fill" data-tone="primary" style="width: {{ round($count / $typeTotal * 100) }}%"></span></span>
                        </div>
                    @empty
                        <p class="admin-muted">No messages in this period.</p>
                    @endforelse
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon name="smartphone" /> Where people use it</h3>
                    <dl class="admin-details">
                        <div><dt>Android app (30 days)</dt><dd class="tabular-nums">{{ number_format($insights['devices']['android']) }} people · {{ number_format($insights['devices']['push']) }} with notifications</dd></div>
                        <div><dt>Browsers (24 hours)</dt><dd class="tabular-nums">{{ number_format($insights['devices']['web']) }} people</dd></div>
                    </dl>
                    <h3 class="admin-section-title mt-4"><x-icon name="hard-drive" /> Storage</h3>
                    <dl class="admin-details">
                        <div><dt>Chat files</dt><dd class="tabular-nums">{{ $bytes($insights['storage']['messages']) }}</dd></div>
                        <div><dt>Status updates</dt><dd class="tabular-nums">{{ $bytes($insights['storage']['statuses']) }}</dd></div>
                        <div><dt>Backups</dt><dd class="tabular-nums">{{ $bytes($insights['storage']['backups']) }}</dd></div>
                    </dl>
                </div>
            </section>
        </div>
    </div>

    <div class="admin-grid admin-grid-even">
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

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Server health</h2>
                    <p class="card-subtitle">Background work, disk and database.</p>
                </div>
                <x-icon name="server" class="text-subtle" />
            </div>
            <div class="card-body">
                @php
                    $schedulerOk = $health['scheduler'] && $health['scheduler']->gt(now()->subMinutes(5));
                    $diskUsed = $health['disk_total'] ? 1 - ($health['disk_free'] / $health['disk_total']) : null;
                @endphp
                <ul class="admin-health">
                    <li data-ok="{{ $schedulerOk ? '1' : '0' }}"><x-icon :name="$schedulerOk ? 'circle-check' : 'triangle-alert'" /> <span><strong>Scheduler</strong> {{ $health['scheduler'] ? 'last ran '.$health['scheduler']->diffForHumans() : 'has not run — add the cron job' }}</span></li>
                    <li data-ok="{{ ($health['queue_failed'] ?? 0) === 0 ? '1' : '0' }}"><x-icon :name="($health['queue_failed'] ?? 0) === 0 ? 'circle-check' : 'triangle-alert'" /> <span><strong>Queue</strong> ({{ $health['queue_driver'] }}) {{ number_format($health['queue_waiting'] ?? 0) }} waiting · {{ number_format($health['queue_failed'] ?? 0) }} failed</span></li>
                    <li data-ok="{{ $diskUsed === null || $diskUsed < 0.9 ? '1' : '0' }}"><x-icon :name="$diskUsed === null || $diskUsed < 0.9 ? 'circle-check' : 'triangle-alert'" /> <span><strong>Disk</strong> {{ $health['disk_free'] ? $bytes((int) $health['disk_free']).' free of '.$bytes((int) $health['disk_total']) : 'unknown' }}</span></li>
                    <li data-ok="1"><x-icon name="database" /> <span><strong>Database</strong> {{ $health['database_bytes'] !== null ? $bytes($health['database_bytes']) : 'size unknown' }}</span></li>
                    <li data-ok="{{ $health['push'] ? '1' : '0' }}"><x-icon :name="$health['push'] ? 'circle-check' : 'circle-alert'" /> <span><strong>Phone notifications</strong> {{ $health['push'] ? 'Firebase ready' : 'Firebase not set up' }}</span></li>
                    <li data-ok="1"><x-icon name="bell-ring" /> <span><strong>Browser notifications</strong> {{ number_format($health['web_push'] ?? 0) }} browsers signed up</span></li>
                    @php
                        $turnHealth = $health['turn'] ?? ['configured' => false, 'check' => null];
                        $turnOk = $turnHealth['configured'] && ($turnHealth['check']['ok'] ?? false);
                    @endphp
                    <li data-ok="{{ $turnOk ? '1' : '0' }}"><x-icon :name="$turnOk ? 'circle-check' : 'circle-alert'" /> <span><strong>Call server (TURN)</strong> <a class="admin-link" href="{{ route('admin.settings') }}#turn">{{ ! $turnHealth['configured'] ? 'not set up — calls on mobile data may fail' : ($turnHealth['check'] === null ? 'set up, not checked yet' : ($turnHealth['check']['ok'] ? 'answers (server check '.$turnHealth['check']['at']->diffForHumans().')' : 'problem found '.$turnHealth['check']['at']->diffForHumans())) }}</a></span></li>
                    <li data-ok="{{ $health['realtime'] ? '1' : '0' }}"><x-icon :name="$health['realtime'] ? 'circle-check' : 'circle-alert'" /> <span><strong>Live updates</strong> {{ $health['realtime'] ? 'Reverb' : 'polling (Reverb off)' }}</span></li>
                    <li data-ok="{{ $health['zip'] ? '1' : '0' }}"><x-icon :name="$health['zip'] ? 'circle-check' : 'circle-alert'" /> <span><strong>ZIP</strong> {{ $health['zip'] ? 'available (exports and backups)' : 'PHP zip extension missing' }}</span></li>
                    <li data-ok="1"><x-icon name="info" /> <span>PHP {{ $health['php'] }} · Laravel {{ $health['laravel'] }}</span></li>
                </ul>
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
