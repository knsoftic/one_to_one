<x-layouts.admin :title="$group->name" :heading="$group->name" :subheading="'Group · '.number_format($group->active_members_count).' people · '.number_format($group->messages_count).' messages'" :back="route('admin.groups')">
    <div class="admin-profile">
        <div class="admin-stack">
            <section class="card admin-profile-card">
                <x-admin.space-avatar :name="$group->name" :src="$group->groupAvatarUrl()" size="2xl" />
                <h2 class="admin-profile-name">{{ $group->name }}</h2>
                @if ($group->description)
                    <p class="admin-profile-about">{{ $group->description }}</p>
                @endif
                <div class="admin-badges justify-center">
                    @if ($group->ended_at)
                        <span class="badge badge-danger">Deleted {{ $group->ended_at->format('j M Y') }}</span>
                    @else
                        <span class="badge badge-success">Open</span>
                    @endif
                    @if ($group->community)
                        <a href="{{ route('admin.communities.show', $group->community) }}" class="badge badge-soft"><x-icon name="layers" class="icon-xs" /> {{ $group->community->name }}</a>
                    @endif
                </div>
                <div class="admin-profile-actions">
                    <a href="{{ route('admin.chats.show', $group) }}" class="btn btn-primary btn-sm"><x-icon name="message-circle" /> Read messages</a>
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title">Details</h3>
                    <dl class="admin-details">
                        <div><dt>Created</dt><dd>{{ $group->created_at?->format('j M Y, g:i A') }}</dd></div>
                        <div><dt>Created by</dt><dd>@if ($group->creator)<a href="{{ route('admin.users.show', $group->creator) }}" class="admin-link">{{ $group->creator->name }}</a>@else — @endif</dd></div>
                        <div><dt>Who can send</dt><dd>{{ $group->only_admins_send ? 'Only admins' : 'Everyone' }}</dd></div>
                        <div><dt>Who can edit info</dt><dd>{{ $group->only_admins_edit ? 'Only admins' : 'Everyone' }}</dd></div>
                        <div><dt>Invite link</dt><dd>{{ $group->invite_token ? 'On' : 'Off' }}</dd></div>
                    </dl>
                </div>
            </section>

            @unless ($group->ended_at)
                <section class="card admin-tool">
                    <div class="card-body">
                        <h3 class="admin-section-title is-danger"><x-icon name="trash-2" /> Delete group</h3>
                        <p class="admin-muted">Everyone is removed and nobody can send messages any more. Members see "An administrator deleted this group".</p>
                        <form method="POST" action="{{ route('admin.groups.destroy', $group) }}" data-confirm="Everyone is removed from &quot;{{ $group->name }}&quot; and it can't be used again." data-confirm-title="Delete this group?" data-confirm-label="Delete group" data-confirm-danger>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger"><x-icon name="trash-2" /> Delete for everyone</button>
                        </form>
                    </div>
                </section>
            @endunless
        </div>

        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">People ({{ number_format($members->count()) }})</h2>
                    <p class="card-subtitle">Admins first.</p>
                </div>
            </div>
            <div class="admin-list">
                @forelse ($members as $member)
                    <a href="{{ route('admin.users.show', $member->user) }}" class="admin-list-row">
                        <x-avatar :user="$member->user" size="md" status />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $member->user->name }}</span>
                            <span class="admin-list-text">{{ '@'.$member->user->username }} · joined {{ $member->joined_at?->diffForHumans() ?? '—' }}</span>
                        </span>
                        <span class="admin-badges">
                            @if ($member->role === 'admin')<span class="badge badge-soft">Group admin</span>@endif
                            @unless ($member->user->isActive())<x-admin.status-badge :user="$member->user" />@endunless
                        </span>
                    </a>
                @empty
                    <p class="admin-muted p-5">Nobody is in this group.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.admin>
