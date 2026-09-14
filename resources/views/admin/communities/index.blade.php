<x-layouts.admin title="Communities" heading="Communities" subheading="Every community, its groups, members and announcements.">
    <form method="GET" action="{{ route('admin.communities') }}" class="admin-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Community name" maxlength="100" aria-label="Search communities">
        </div>
        <div class="admin-filter-actions"><button type="submit" class="btn btn-primary"><x-icon name="search" /> Search</button></div>
    </form>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Community</th>
                        <th class="text-right">Members</th>
                        <th class="text-right hidden md:table-cell">Groups</th>
                        <th class="hidden lg:table-cell">Created by</th>
                        <th class="hidden md:table-cell">Created</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($communities as $community)
                        <tr>
                            <td>
                                <a href="{{ route('admin.communities.show', $community) }}" class="admin-person">
                                    <x-admin.space-avatar :name="$community->name" :src="$community->avatarUrl()" size="sm" />
                                    <span class="min-w-0">
                                        <span class="admin-person-name">{{ $community->name }}</span>
                                        <span class="admin-person-meta">{{ \Illuminate\Support\Str::limit($community->description ?: 'No description', 60) }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($community->announcement?->active_members_count ?? 0) }}</td>
                            <td class="text-right tabular-nums hidden md:table-cell">{{ number_format($community->groups_count) }}</td>
                            <td class="hidden lg:table-cell text-sm">{{ $community->creator?->name ?? '—' }}</td>
                            <td class="hidden md:table-cell text-sm text-muted">{{ $community->created_at?->format('j M Y') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('admin.communities.show', $community) }}" class="btn btn-secondary btn-sm">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><x-icon name="layers" /></div><div class="empty-state-title">No communities found</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$communities" label="communities" />
    </div>
</x-layouts.admin>
