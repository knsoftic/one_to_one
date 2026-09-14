<x-layouts.admin title="Search messages" heading="Search messages" subheading="Find text and file names in every chat. Searches are recorded in the audit log.">
    <form method="GET" action="{{ route('admin.messages') }}" class="admin-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $q }}" class="form-control" placeholder="At least 2 letters, e.g. a phone number, a word or a file name" maxlength="100" minlength="2" aria-label="Search messages" autofocus>
        </div>
        <div class="admin-filter-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="search" /> Search</button>
        </div>
    </form>

    @if ($results === null)
        <div class="card">
            <div class="empty-state">
                <div class="empty-state-icon"><x-icon name="file-search" /></div>
                <div class="empty-state-title">Search every message</div>
                <div class="empty-state-text">Type at least 2 letters. Deleted messages and app notices aren't searched.</div>
            </div>
        </div>
    @else
        <div class="card">
            <div class="admin-list">
                @forelse ($results as $message)
                    @php($chat = $message->conversation)
                    <a href="{{ $chat ? route('admin.chats.show', ['conversation' => $chat, 'before' => $message->id + 1]).'#message-'.$message->id : '#' }}" class="admin-list-row admin-result">
                        @if ($message->sender)
                            <x-avatar :user="$message->sender" size="md" />
                        @else
                            <x-admin.space-avatar name="?" />
                        @endif
                        <span class="admin-list-body">
                            <span class="admin-list-title">
                                {{ $message->sender?->name ?? 'Deleted account' }}
                                <span class="admin-list-in">in {{ $chat ? $content->title($chat) : 'a deleted chat' }}</span>
                            </span>
                            <span class="admin-result-text">{!! preg_replace('/('.preg_quote(e($q), '/').')/iu', '<mark>$1</mark>', e(\Illuminate\Support\Str::limit($message->message ?: $message->attachment_name ?: $message->preview(), 220))) !!}</span>
                        </span>
                        <span class="admin-list-side">{{ $message->created_at?->format('j M Y') }}<br>{{ $message->created_at?->format('g:i A') }}</span>
                    </a>
                @empty
                    <div class="empty-state">
                        <div class="empty-state-icon"><x-icon name="search" /></div>
                        <div class="empty-state-title">No messages found</div>
                        <div class="empty-state-text">Nothing matches "{{ $q }}".</div>
                    </div>
                @endforelse
            </div>
            <x-admin.pager :items="$results" label="messages" />
        </div>
    @endif
</x-layouts.admin>
