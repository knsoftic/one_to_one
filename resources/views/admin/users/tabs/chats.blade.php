<section class="card">
    <div class="card-header admin-card-head">
        <div>
            <h2 class="card-title">Chats</h2>
            <p class="card-subtitle">{{ number_format($chats->total()) }} chats. Opening a chat is recorded in the audit log.</p>
        </div>
        <nav class="admin-tabs" aria-label="Kind of chat">
            @foreach ([null => 'All', 'direct' => 'One-to-one', 'group' => 'Groups', 'channel' => 'Channels', 'broadcast' => 'Broadcast lists'] as $value => $label)
                <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'chats', 'type' => $value ?: null]) }}" @class(['admin-tab', 'is-active' => request('type') == $value])>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
    <div class="admin-list">
        @forelse ($chats as $chat)
            @php($other = $chat->hasMembers() ? null : ((int) $chat->user_one_id === (int) $user->id ? $chat->userTwo : $chat->userOne))
            <a href="{{ route('admin.chats.show', $chat) }}" class="admin-list-row">
                @if ($other)
                    <x-avatar :user="$other" size="md" />
                @else
                    <x-admin.space-avatar :name="$content->title($chat)" :icon="$chat->isChannel() ? 'rss' : ($chat->isBroadcast() ? 'megaphone' : null)" />
                @endif
                <span class="admin-list-body">
                    <span class="admin-list-title">{{ $other ? $other->name : $content->title($chat) }}</span>
                    <span class="admin-list-text">{{ $content->typeLabel($chat) }} · {{ number_format($chat->messages_count) }} messages · {{ $chat->updated_at?->diffForHumans() }}</span>
                </span>
                <x-icon name="chevron-right" class="text-subtle" />
            </a>
        @empty
            <p class="admin-muted p-5">No chats here.</p>
        @endforelse
    </div>
    <x-admin.pager :items="$chats" label="chats" />
</section>
