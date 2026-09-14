<x-layouts.admin title="Groups" heading="Groups" subheading="Every group chat, its people and messages.">
    <form method="GET" action="{{ route('admin.groups') }}" class="admin-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Group name" maxlength="100" aria-label="Search groups">
        </div>
        <select name="state" class="form-control admin-select" aria-label="Show">
            <option value="active" @selected(($filters['state'] ?? 'active') === 'active')>Open groups</option>
            <option value="deleted" @selected(($filters['state'] ?? '') === 'deleted')>Deleted groups</option>
            <option value="all" @selected(($filters['state'] ?? '') === 'all')>All groups</option>
        </select>
        <div class="admin-filter-actions"><button type="submit" class="btn btn-primary"><x-icon name="search" /> Search</button></div>
    </form>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th class="text-right">People</th>
                        <th class="text-right hidden md:table-cell">Messages</th>
                        <th class="hidden lg:table-cell">Created by</th>
                        <th class="hidden md:table-cell">Last activity</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        <tr>
                            <td>
                                <a href="{{ route('admin.groups.show', $group) }}" class="admin-person">
                                    <x-admin.space-avatar :name="$group->name" :src="$group->groupAvatarUrl()" size="sm" />
                                    <span class="min-w-0">
                                        <span class="admin-person-name">{{ $group->name }}</span>
                                        <span class="admin-person-meta">
                                            @if ($group->community) {{ $group->community->name }} · @endif
                                            @if ($group->ended_at) <span class="text-danger">Deleted</span> @else Open @endif
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($group->active_members_count) }}</td>
                            <td class="text-right tabular-nums hidden md:table-cell">{{ number_format($group->messages_count) }}</td>
                            <td class="hidden lg:table-cell text-sm">{{ $group->creator?->name ?? '—' }}</td>
                            <td class="hidden md:table-cell text-sm text-muted">{{ $group->updated_at?->diffForHumans() }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('admin.chats.show', $group) }}" class="btn btn-secondary btn-sm"><x-icon name="message-circle" /> Messages</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><x-icon name="users-round" /></div><div class="empty-state-title">No groups found</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$groups" label="groups" />
    </div>
</x-layouts.admin>
