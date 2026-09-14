<x-layouts.admin :title="$title" :heading="$title" :subheading="$typeLabel.' · '.number_format($chat->messages()->count()).' messages'" :back="route('admin.chats')">
    @php
        // One-to-one chats: the first person on the left, the second on the right (green).
        $rightId = $chat->hasMembers() ? null : (int) $chat->user_two_id;
        $lastDay = null;
    @endphp

    <div class="admin-viewer">
        <section class="admin-viewer-main card">
            <div class="admin-viewer-bar">
                <x-icon name="eye" />
                <span>You're viewing this chat as an administrator. It has been recorded in the audit log.</span>
            </div>

            <div class="admin-thread" id="thread">
                @if ($older)
                    <a href="{{ route('admin.chats.show', ['conversation' => $chat, 'before' => $messages->first()->id]) }}#thread" class="admin-thread-older"><x-icon name="chevron-up" /> Older messages</a>
                @elseif ($messages->isNotEmpty())
                    <p class="admin-thread-start">Start of the chat</p>
                @endif

                @forelse ($messages as $message)
                    @php($day = $message->created_at?->format('Y-m-d'))
                    @if ($day !== $lastDay)
                        @php($lastDay = $day)
                        <div class="admin-day"><span>{{ $message->created_at?->isToday() ? 'Today' : ($message->created_at?->isYesterday() ? 'Yesterday' : $message->created_at?->format('j F Y')) }}</span></div>
                    @endif
                    @include('admin.chats.message', ['message' => $message, 'isRight' => $rightId !== null && (int) $message->sender_id === $rightId])
                @empty
                    <div class="empty-state">
                        <div class="empty-state-icon"><x-icon name="message-square-off" /></div>
                        <div class="empty-state-title">No messages</div>
                        <div class="empty-state-text">Nothing has been sent in this chat{{ $chat->hasMembers() ? '' : ' yet' }}.</div>
                    </div>
                @endforelse
            </div>
        </section>

        <aside class="admin-stack">
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title">About this chat</h3>
                    <dl class="admin-details">
                        <div><dt>Type</dt><dd>{{ $typeLabel }}</dd></div>
                        <div><dt>Started</dt><dd>{{ $chat->created_at?->format('j M Y, g:i A') }}</dd></div>
                        @if ($chat->name)
                            <div><dt>Name</dt><dd>{{ $chat->name }}</dd></div>
                        @endif
                        @if ($chat->description)
                            <div><dt>Description</dt><dd>{{ $chat->description }}</dd></div>
                        @endif
                        @if ($chat->creator)
                            <div><dt>Created by</dt><dd><a href="{{ route('admin.users.show', $chat->creator) }}" class="admin-link">{{ $chat->creator->name }}</a></dd></div>
                        @endif
                        @if ($chat->disappearing_seconds)
                            <div><dt>Disappearing</dt><dd>On</dd></div>
                        @endif
                        @if ($chat->ended_at)
                            <div><dt>Deleted</dt><dd class="text-danger">{{ $chat->ended_at->format('j M Y') }}</dd></div>
                        @endif
                    </dl>
                    <div class="admin-side-links">
                        @if ($chat->isGroup() && ! $chat->is_announcement)
                            <a href="{{ route('admin.groups.show', $chat) }}" class="btn btn-secondary btn-sm"><x-icon name="users-round" /> Group page</a>
                        @elseif ($chat->is_announcement && $chat->community)
                            <a href="{{ route('admin.communities.show', $chat->community) }}" class="btn btn-secondary btn-sm"><x-icon name="layers" /> Community page</a>
                        @elseif ($chat->isChannel())
                            <a href="{{ route('admin.channels.show', $chat) }}" class="btn btn-secondary btn-sm"><x-icon name="rss" /> Channel page</a>
                        @endif
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-body">
                    @if (! $chat->hasMembers())
                        <h3 class="admin-section-title">People</h3>
                        @foreach (array_filter([$chat->userOne, $chat->isSelf() ? null : $chat->userTwo]) as $person)
                            <a href="{{ route('admin.users.show', $person) }}" class="admin-list-row is-compact">
                                <x-avatar :user="$person" size="sm" status />
                                <span class="admin-list-body">
                                    <span class="admin-list-title">{{ $person->name }}</span>
                                    <span class="admin-list-text">{{ '@'.$person->username }}</span>
                                </span>
                                <x-admin.status-badge :user="$person" />
                            </a>
                        @endforeach
                    @elseif ($chat->isBroadcast())
                        <h3 class="admin-section-title">Recipients ({{ $broadcastRecipients->count() }})</h3>
                        @foreach ($broadcastRecipients->take(100) as $person)
                            <a href="{{ route('admin.users.show', $person) }}" class="admin-list-row is-compact">
                                <x-avatar :user="$person" size="sm" />
                                <span class="admin-list-body"><span class="admin-list-title">{{ $person->name }}</span></span>
                            </a>
                        @endforeach
                    @else
                        <h3 class="admin-section-title">{{ $chat->isChannel() ? 'Followers' : 'Members' }} ({{ number_format($members->count()) }}{{ $members->count() >= 300 ? '+' : '' }})</h3>
                        @foreach ($members->take(100) as $member)
                            <a href="{{ route('admin.users.show', $member->user) }}" class="admin-list-row is-compact">
                                <x-avatar :user="$member->user" size="sm" />
                                <span class="admin-list-body">
                                    <span class="admin-list-title">{{ $member->user->name }}</span>
                                    <span class="admin-list-text">{{ '@'.$member->user->username }}</span>
                                </span>
                                @if ($member->role === 'admin')
                                    <span class="badge badge-soft">Admin</span>
                                @endif
                            </a>
                        @endforeach
                    @endif
                </div>
            </section>
        </aside>
    </div>
</x-layouts.admin>
