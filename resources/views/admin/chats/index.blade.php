<x-layouts.admin title="Chats" :heading="$person ? $person->name.'\'s chats' : 'Chats'" subheading="Every one-to-one chat, group, community, channel and broadcast list." :back="$person ? route('admin.users.show', $person) : null">
    <form method="GET" action="{{ route('admin.chats') }}" class="admin-filters">
        @if ($person)
            <input type="hidden" name="user" value="{{ $person->id }}">
        @endif
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Group name or person in the chat" maxlength="100" aria-label="Search chats">
        </div>
        <div class="admin-filter-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="search" /> Search</button>
            @if (array_filter($filters))
                <a href="{{ route('admin.chats') }}" class="btn btn-ghost">Reset</a>
            @endif
        </div>
    </form>

    <nav class="admin-tabs" aria-label="Chat types">
        <a href="{{ route('admin.chats', array_filter(['q' => $filters['q'] ?? null, 'user' => $person?->id])) }}" @class(['admin-tab', 'is-active' => empty($filters['type'])])>All</a>
        @foreach (\App\Services\AdminContentService::CHAT_TYPES as $type => $label)
            <a href="{{ route('admin.chats', array_filter(['type' => $type, 'q' => $filters['q'] ?? null, 'user' => $person?->id])) }}" @class(['admin-tab', 'is-active' => ($filters['type'] ?? null) === $type])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="card">
        <div class="admin-list">
            @forelse ($chats as $chat)
                @php
                    $title = $content->title($chat);
                    $isDirect = ! $chat->hasMembers();
                @endphp
                <a href="{{ route('admin.chats.show', $chat) }}" class="admin-list-row admin-chat-row">
                    @if ($isDirect)
                        <span class="admin-pair">
                            @if ($chat->userOne)<x-avatar :user="$chat->userOne" size="sm" />@endif
                            @if ($chat->userTwo && ! $chat->isSelf())<x-avatar :user="$chat->userTwo" size="sm" />@endif
                        </span>
                    @else
                        <x-admin.space-avatar :name="$title" :src="$chat->groupAvatarUrl()" :icon="$chat->isChannel() ? 'rss' : ($chat->isBroadcast() ? 'megaphone' : null)" />
                    @endif
                    <span class="admin-list-body">
                        <span class="admin-list-title">{{ $title }}</span>
                        <span class="admin-list-text">
                            <span class="badge badge-muted">{{ $content->typeLabel($chat) }}</span>
                            {{ number_format($chat->messages_count) }} messages
                            @unless ($isDirect) · {{ number_format($chat->active_members_count) }} {{ $chat->isChannel() ? 'followers' : 'people' }} @endunless
                            @if ($chat->ended_at) · <span class="text-danger">deleted</span> @endif
                        </span>
                    </span>
                    <span class="admin-list-side">{{ $chat->updated_at?->diffForHumans(short: true) }}</span>
                </a>
            @empty
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="message-circle" /></div>
                    <div class="empty-state-title">No chats found</div>
                    <div class="empty-state-text">Try another search or type.</div>
                </div>
            @endforelse
        </div>
        <x-admin.pager :items="$chats" label="chats" />
    </div>
</x-layouts.admin>
