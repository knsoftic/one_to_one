<x-layouts.admin :title="$community->name" :heading="$community->name" :subheading="'Community · '.number_format($announcement?->active_members_count ?? 0).' members · '.$groups->count().' groups'" :back="route('admin.communities')">
    <div class="admin-profile">
        <div class="admin-stack">
            <section class="card admin-profile-card">
                <x-admin.space-avatar :name="$community->name" :src="$community->avatarUrl()" size="2xl" />
                <h2 class="admin-profile-name">{{ $community->name }}</h2>
                @if ($community->description)
                    <p class="admin-profile-about">{{ $community->description }}</p>
                @endif
                <p class="admin-muted">Created {{ $community->created_at?->format('j M Y') }} by {{ $community->creator?->name ?? 'a deleted account' }}</p>
                @if ($announcement)
                    <div class="admin-profile-actions">
                        <a href="{{ route('admin.chats.show', $announcement) }}" class="btn btn-primary btn-sm"><x-icon name="megaphone" /> Announcements ({{ number_format($announcement->messages_count) }})</a>
                    </div>
                @endif
            </section>

            <section class="card">
                <div class="card-header admin-card-head">
                    <div>
                        <h2 class="card-title">Groups ({{ $groups->count() }})</h2>
                        <p class="card-subtitle">Groups inside this community.</p>
                    </div>
                </div>
                <div class="admin-list">
                    @forelse ($groups as $group)
                        <a href="{{ route('admin.groups.show', $group) }}" class="admin-list-row">
                            <x-admin.space-avatar :name="$group->name" :src="$group->groupAvatarUrl()" />
                            <span class="admin-list-body">
                                <span class="admin-list-title">{{ $group->name }}</span>
                                <span class="admin-list-text">{{ number_format($group->active_members_count) }} people</span>
                            </span>
                            <x-icon name="chevron-right" class="text-subtle" />
                        </a>
                    @empty
                        <p class="admin-muted p-5">No groups yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="card admin-tool">
                <div class="card-body">
                    <h3 class="admin-section-title is-danger"><x-icon name="trash-2" /> Delete community</h3>
                    <p class="admin-muted">The community and its announcements are closed for everyone. Its groups stay as ordinary groups.</p>
                    <form method="POST" action="{{ route('admin.communities.destroy', $community) }}" data-confirm="&quot;{{ $community->name }}&quot; will be deleted. Its groups stay as ordinary groups." data-confirm-title="Delete this community?" data-confirm-label="Delete community" data-confirm-danger>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger"><x-icon name="trash-2" /> Delete community</button>
                    </form>
                </div>
            </section>
        </div>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Members ({{ number_format($members->count()) }}{{ $members->count() >= 300 ? '+' : '' }})</h2>
                    <p class="card-subtitle">Members don't see each other in the app; you see everyone here.</p>
                </div>
            </div>
            <div class="admin-list">
                @forelse ($members as $member)
                    <a href="{{ route('admin.users.show', $member->user) }}" class="admin-list-row">
                        <x-avatar :user="$member->user" size="md" />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $member->user->name }}</span>
                            <span class="admin-list-text">{{ '@'.$member->user->username }} · joined {{ $member->joined_at?->diffForHumans() ?? '—' }}</span>
                        </span>
                        @if ($member->role === 'admin')<span class="badge badge-soft">Community admin</span>@endif
                    </a>
                @empty
                    <p class="admin-muted p-5">No members.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.admin>
