@php
    $typeLabels = ['text' => 'Text', 'image' => 'Photos & GIFs', 'video' => 'Videos', 'voice' => 'Voice messages', 'document' => 'Documents', 'sticker' => 'Stickers', 'location' => 'Locations', 'contact' => 'Contact cards', 'poll' => 'Polls', 'call' => 'Call history', 'system' => 'Notices'];
    $totalTypes = max(1, collect($activityData['types'])->sum('count'));
    $days = $activityData['days'];
    $busiestHour = collect($activityData['hours'])->search(max($activityData['hours']));
@endphp
<div class="admin-stack">
    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Messages sent, last 90 days</h2>
                <p class="card-subtitle">{{ number_format(collect($days)->sum('count')) }} messages · busiest day {{ number_format(collect($days)->max('count')) }}</p>
            </div>
            <x-icon name="trending-up" class="text-subtle" />
        </div>
        <div class="card-body">
            <x-admin.bars :series="collect($days)->map(fn ($d) => ['label' => \Illuminate\Support\Carbon::parse($d['date'])->format('j M'), 'title' => \Illuminate\Support\Carbon::parse($d['date'])->format('D j M Y'), 'count' => $d['count']])->all()" label="Messages sent per day for the last 90 days" height="12rem" />
        </div>
    </section>

    <div class="admin-grid admin-grid-even">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Time of day</h2>
                    <p class="card-subtitle">Last 90 days, server time ({{ config('app.timezone') }}){{ max($activityData['hours']) ? ' · most active around '.sprintf('%02d:00', $busiestHour) : '' }}</p>
                </div>
            </div>
            <div class="card-body">
                <x-admin.bars :series="collect($activityData['hours'])->map(fn ($n, $h) => ['label' => $h % 6 === 0 ? sprintf('%02d', $h) : '', 'title' => sprintf('%02d:00–%02d:59', $h, $h), 'count' => $n])->values()->all()" label="Messages sent by hour of the day" every="1" height="9rem" />
            </div>
        </section>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">What they send</h2>
                    <p class="card-subtitle">All messages by kind, with file sizes.</p>
                </div>
            </div>
            <div class="card-body flex flex-col gap-3">
                @forelse ($activityData['types'] as $type => $row)
                    <div class="health-row">
                        <span class="flex items-center justify-between text-sm gap-3">
                            <span class="font-semibold">{{ $typeLabels[$type] ?? ucfirst($type) }}</span>
                            <span class="text-muted tabular-nums">{{ number_format($row['count']) }}@if ($row['bytes']) · {{ $bytes($row['bytes']) }}@endif</span>
                        </span>
                        <span class="health-track"><span class="health-fill" data-tone="primary" style="width: {{ round($row['count'] / $totalTypes * 100) }}%"></span></span>
                    </div>
                @empty
                    <p class="admin-muted">No messages yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Where they write most</h2>
                <p class="card-subtitle">Top chats by messages sent. Opening a chat is recorded in the audit log.</p>
            </div>
        </div>
        <div class="admin-list">
            @forelse ($activityData['top_chats'] as $row)
                <a href="{{ route('admin.chats.show', $row['chat']) }}" class="admin-list-row">
                    @if ($row['other'])
                        <x-avatar :user="$row['other']" size="md" />
                    @else
                        <x-admin.space-avatar :name="$row['title']" :icon="$row['chat']->isChannel() ? 'rss' : ($row['chat']->isBroadcast() ? 'megaphone' : null)" />
                    @endif
                    <span class="admin-list-body">
                        <span class="admin-list-title">{{ $row['title'] }}</span>
                        <span class="admin-list-text">{{ $row['type'] }} · last message {{ $row['last_at']->diffForHumans() }}</span>
                    </span>
                    <span class="admin-list-value tabular-nums">{{ number_format($row['messages']) }}</span>
                    <x-icon name="chevron-right" class="text-subtle" />
                </a>
            @empty
                <p class="admin-muted p-5">No messages yet.</p>
            @endforelse
        </div>
    </section>
</div>
