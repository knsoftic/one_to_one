@php
    $reasonLabels = ['completed' => ['badge-success', 'Answered'], 'declined' => ['badge-warning', 'Declined'], 'missed' => ['badge-danger', 'Missed'], 'cancelled' => ['badge-muted', 'Cancelled'], 'busy' => ['badge-warning', 'Busy'], 'failed' => ['badge-danger', 'Failed']];
    $duration = fn (?int $s) => $s ? ($s >= 3600 ? gmdate('G:i:s', $s) : gmdate('i:s', $s)) : '—';
@endphp
<div class="admin-stack">
    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Calls</h2>
                <p class="card-subtitle">{{ number_format($calls->total()) }} voice and video calls. Only times and lengths are stored, never the audio or video.</p>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>With</th>
                        <th>Call</th>
                        <th class="text-right">Length</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($calls as $call)
                        @php($outgoing = (int) $call->caller_id === (int) $user->id)
                        @php($other = $outgoing ? $call->callee : $call->caller)
                        @php([$badge, $label] = $reasonLabels[$call->end_reason] ?? ['badge-soft', ucfirst($call->status)])
                        <tr>
                            <td class="whitespace-nowrap text-sm">
                                <span class="block">{{ $call->created_at->format('j M Y') }}</span>
                                <span class="block text-xs text-muted">{{ $call->created_at->format('g:i A') }}</span>
                            </td>
                            <td>
                                @if ($other)
                                    <a href="{{ route('admin.users.show', $other) }}" class="admin-person">
                                        <x-avatar :user="$other" size="sm" />
                                        <span class="admin-person-name">{{ $other->name }}</span>
                                    </a>
                                @else
                                    <span class="text-muted">Deleted account</span>
                                @endif
                            </td>
                            <td class="text-sm">
                                <span class="inline-flex items-center gap-1"><x-icon :name="$outgoing ? 'phone-outgoing' : 'phone-incoming'" class="icon-sm" /> {{ $outgoing ? 'Outgoing' : 'Incoming' }} {{ $call->type }}</span>
                                <span class="block mt-1"><span class="badge {{ $badge }}">{{ $label }}</span></span>
                            </td>
                            <td class="text-right tabular-nums text-sm">{{ $duration($call->duration) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><div class="empty-state-icon"><x-icon name="phone" /></div><div class="empty-state-title">No calls yet</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$calls" label="calls" />
    </section>

    @if ($groupCalls->isNotEmpty())
        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="users" /> Group calls (latest 20)</h3>
                @foreach ($groupCalls as $room)
                    <div class="admin-list-row is-compact">
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ ucfirst($room->type) }} group call · {{ (int) $room->host_id === (int) $user->id ? 'started by them' : 'invited' }}</span>
                            <span class="admin-list-text">
                                {{ ucfirst($room->status) }} · {{ \Illuminate\Support\Carbon::parse($room->created_at)->format('j M Y, g:i A') }}
                                @if ($room->joined_at && $room->left_at) · {{ $duration(\Illuminate\Support\Carbon::parse($room->joined_at)->diffInSeconds(\Illuminate\Support\Carbon::parse($room->left_at))) }} @endif
                            </span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
