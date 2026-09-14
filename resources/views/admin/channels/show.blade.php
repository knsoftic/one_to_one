<x-layouts.admin :title="$channel->name" :heading="$channel->name" :subheading="'Channel · '.number_format($channel->active_members_count).' followers · '.number_format($channel->messages_count).' updates'" :back="route('admin.channels')">
    <div class="admin-profile">
        <div class="admin-stack">
            <section class="card admin-profile-card">
                <x-admin.space-avatar :name="$channel->name" :src="$channel->groupAvatarUrl()" size="2xl" />
                <h2 class="admin-profile-name">{{ $channel->name }}</h2>
                @if ($channel->description)
                    <p class="admin-profile-about">{{ $channel->description }}</p>
                @endif
                <div class="admin-profile-actions">
                    <a href="{{ route('admin.chats.show', $channel) }}" class="btn btn-primary btn-sm"><x-icon name="message-circle" /> Read updates</a>
                </div>
            </section>

            <section class="card admin-tool">
                <div class="card-body">
                    <h3 class="admin-section-title is-danger"><x-icon name="trash-2" /> Delete channel</h3>
                    <p class="admin-muted">The channel, all its updates and files are removed for every follower. This can't be undone.</p>
                    <form method="POST" action="{{ route('admin.channels.destroy', $channel) }}" data-confirm="&quot;{{ $channel->name }}&quot; and all its updates will be deleted for everyone." data-confirm-title="Delete this channel?" data-confirm-label="Delete channel" data-confirm-danger>
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger"><x-icon name="trash-2" /> Delete channel</button>
                    </form>
                </div>
            </section>
        </div>

        <div class="admin-stack">
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title">Details</h3>
                    <dl class="admin-details">
                        <div><dt>Created</dt><dd>{{ $channel->created_at?->format('j M Y, g:i A') }}</dd></div>
                        <div><dt>Owner</dt><dd>@if ($channel->creator)<a href="{{ route('admin.users.show', $channel->creator) }}" class="admin-link">{{ $channel->creator->name }}</a>@else — @endif</dd></div>
                        <div><dt>Followers</dt><dd>{{ number_format($channel->active_members_count) }}</dd></div>
                        <div><dt>Updates</dt><dd>{{ number_format($channel->messages_count) }}</dd></div>
                        <div><dt>Link</dt><dd>{{ $channel->invite_token ? route('channels.link', $channel->invite_token) : '—' }}</dd></div>
                    </dl>
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title">Channel admins</h3>
                    @forelse ($admins as $member)
                        @if ($member->user)
                            <a href="{{ route('admin.users.show', $member->user) }}" class="admin-list-row is-compact">
                                <x-avatar :user="$member->user" size="sm" />
                                <span class="admin-list-body">
                                    <span class="admin-list-title">{{ $member->user->name }}</span>
                                    <span class="admin-list-text">{{ '@'.$member->user->username }}</span>
                                </span>
                            </a>
                        @endif
                    @empty
                        <p class="admin-muted">No admins.</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>
</x-layouts.admin>
