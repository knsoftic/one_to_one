<section class="card">
    <div class="card-header admin-card-head">
        <div>
            <h2 class="card-title">Groups, communities and channels</h2>
            <p class="card-subtitle">Where {{ $user->name }} is or was a member, with their role.</p>
        </div>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th class="hidden md:table-cell text-right">Members</th>
                    <th class="hidden md:table-cell">Joined</th>
                    <th>State</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($memberships as $member)
                    @php($chat = $member->conversation)
                    <tr>
                        <td>
                            <a href="{{ $chat->isChannel() ? route('admin.channels.show', $chat) : ($chat->is_announcement && $chat->community_id ? route('admin.communities.show', $chat->community_id) : route('admin.groups.show', $chat)) }}" class="admin-person">
                                <x-admin.space-avatar :name="$content->title($chat)" :icon="$chat->isChannel() ? 'rss' : ($chat->is_announcement ? 'layers' : null)" />
                                <span class="min-w-0">
                                    <span class="admin-person-name">{{ $content->title($chat) }}</span>
                                    <span class="admin-person-meta">{{ $content->typeLabel($chat) }}{{ (int) $chat->created_by === (int) $user->id ? ' · created by them' : '' }}</span>
                                </span>
                            </a>
                        </td>
                        <td><span class="badge {{ $member->role === 'admin' ? 'badge-soft' : 'badge-muted' }}">{{ ucfirst($member->role) }}</span></td>
                        <td class="hidden md:table-cell text-right tabular-nums">{{ number_format($chat->active_members_count) }}</td>
                        <td class="hidden md:table-cell text-sm text-muted whitespace-nowrap">{{ $member->joined_at?->format('j M Y') ?? '—' }}</td>
                        <td>
                            @if ($member->left_at)
                                <span class="badge badge-muted">Left {{ $member->left_at->format('j M Y') }}</span>
                            @elseif ($chat->ended_at)
                                <span class="badge badge-danger">Group deleted</span>
                            @else
                                <span class="badge badge-success">Member</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty-state"><div class="empty-state-icon"><x-icon name="users-round" /></div><div class="empty-state-title">Not in any group, community or channel</div></div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <x-admin.pager :items="$memberships" label="memberships" />
</section>
