<x-layouts.admin title="Channels" heading="Channels" subheading="Every channel, its followers and updates.">
    <form method="GET" action="{{ route('admin.channels') }}" class="admin-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Channel name" maxlength="100" aria-label="Search channels">
        </div>
        <div class="admin-filter-actions"><button type="submit" class="btn btn-primary"><x-icon name="search" /> Search</button></div>
    </form>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th class="text-right">Followers</th>
                        <th class="text-right hidden md:table-cell">Updates</th>
                        <th class="hidden lg:table-cell">Owner</th>
                        <th class="hidden md:table-cell">Created</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($channels as $channel)
                        <tr>
                            <td>
                                <a href="{{ route('admin.channels.show', $channel) }}" class="admin-person">
                                    <x-admin.space-avatar :name="$channel->name" :src="$channel->groupAvatarUrl()" size="sm" />
                                    <span class="min-w-0">
                                        <span class="admin-person-name">{{ $channel->name }}</span>
                                        <span class="admin-person-meta">{{ \Illuminate\Support\Str::limit($channel->description ?: 'No description', 60) }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="text-right tabular-nums">{{ number_format($channel->active_members_count) }}</td>
                            <td class="text-right tabular-nums hidden md:table-cell">{{ number_format($channel->messages_count) }}</td>
                            <td class="hidden lg:table-cell text-sm">{{ $channel->creator?->name ?? '—' }}</td>
                            <td class="hidden md:table-cell text-sm text-muted">{{ $channel->created_at?->format('j M Y') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('admin.chats.show', $channel) }}" class="btn btn-secondary btn-sm"><x-icon name="message-circle" /> Updates</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><x-icon name="rss" /></div><div class="empty-state-title">No channels found</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$channels" label="channels" />
    </div>
</x-layouts.admin>
