{{-- One message in the admin chat viewer. --}}
@php
    $meta = $message->attachment_meta ?? [];
    $type = $message->message_type;
    $deleted = (bool) $message->deleted_for_everyone;
    $sender = $message->sender;
    $fileUrl = $message->attachment ? route('admin.messages.attachment', $message) : null;
    $thumbUrl = $message->attachment && ! empty($meta['thumbnail']) ? route('admin.messages.attachment', [$message, 'variant' => 'thumbnail']) : $fileUrl;
    $bytes = (int) $message->attachment_size;
    $size = match (true) {
        $bytes >= 1048576 => round($bytes / 1048576, 1).' MB',
        $bytes >= 1024 => round($bytes / 1024).' KB',
        $bytes > 0 => $bytes.' B',
        default => null,
    };
    $hue = $sender ? (int) $sender->avatar_hue : 200;
@endphp

@if ($type === \App\Models\Message::TYPE_SYSTEM)
    <div class="admin-notice" id="message-{{ $message->id }}"><span>{{ $message->systemText() ?: 'Notice' }}</span></div>
@else
    <article @class(['admin-msg', 'is-right' => $isRight]) id="message-{{ $message->id }}">
        @unless ($isRight)
            @if ($sender)
                <a href="{{ route('admin.users.show', $sender) }}" class="admin-msg-avatar" title="{{ $sender->name }}"><x-avatar :user="$sender" size="sm" /></a>
            @else
                <span class="admin-msg-avatar"><x-admin.space-avatar name="?" size="sm" /></span>
            @endif
        @endunless

        <div class="admin-bubble">
            <div class="admin-bubble-head">
                <span class="admin-bubble-sender" style="--hue: {{ $hue }}">{{ $sender?->name ?? 'Deleted account' }}</span>
                @if ($message->forward_count)
                    <span class="admin-bubble-tag"><x-icon name="forward" /> Forwarded</span>
                @endif
                @if ($meta['view_once'] ?? false)
                    <span class="admin-bubble-tag"><x-icon name="eye" /> View once</span>
                @endif
                @if ($message->broadcast_message_id)
                    <span class="admin-bubble-tag"><x-icon name="megaphone" /> Broadcast</span>
                @endif
            </div>

            @if ($message->replyTo && ! $deleted)
                <div class="admin-quote">
                    <strong>{{ $message->replyTo->sender?->name ?? 'Deleted account' }}</strong>
                    <span>{{ $message->replyTo->preview(90) }}</span>
                </div>
            @endif

            @if ($deleted)
                <p class="admin-bubble-deleted"><x-icon name="ban" /> This message was deleted</p>
            @else
                @switch($type)
                    @case(\App\Models\Message::TYPE_IMAGE)
                    @case(\App\Models\Message::TYPE_STICKER)
                        @if ($fileUrl)
                            <a href="{{ $fileUrl }}" target="_blank" rel="noopener" @class(['admin-media', 'is-sticker' => $type === 'sticker'])>
                                <img src="{{ $thumbUrl }}" alt="{{ $type === 'sticker' ? 'Sticker' : 'Photo' }}" loading="lazy">
                            </a>
                        @else
                            <p class="admin-bubble-muted"><x-icon name="image-off" /> {{ ($meta['view_once'] ?? false) ? 'Opened view once photo (removed)' : 'Photo no longer available' }}</p>
                        @endif
                        @break

                    @case(\App\Models\Message::TYPE_VIDEO)
                        @if ($fileUrl)
                            <video class="admin-media" controls preload="none" src="{{ $fileUrl }}" @if (! empty($meta['thumbnail'])) poster="{{ $thumbUrl }}" @endif></video>
                        @else
                            <p class="admin-bubble-muted"><x-icon name="video-off" /> Video no longer available</p>
                        @endif
                        @break

                    @case(\App\Models\Message::TYPE_VOICE)
                        @if ($fileUrl)
                            <audio class="admin-audio" controls preload="none" src="{{ $fileUrl }}"></audio>
                        @else
                            <p class="admin-bubble-muted"><x-icon name="mic-off" /> Voice message no longer available</p>
                        @endif
                        @break

                    @case(\App\Models\Message::TYPE_DOCUMENT)
                        @if ($fileUrl)
                            <a href="{{ route('admin.messages.attachment', [$message, 'download' => 1]) }}" class="admin-file">
                                <x-icon name="file-text" />
                                <span class="admin-file-body">
                                    <span class="admin-file-name">{{ $message->attachment_name ?: 'Document' }}</span>
                                    <span class="admin-file-meta">{{ $size }}{{ $message->attachment_mime ? ' · '.$message->attachment_mime : '' }}</span>
                                </span>
                                <x-icon name="download" />
                            </a>
                        @else
                            <p class="admin-bubble-muted"><x-icon name="file" /> {{ $message->attachment_name ?: 'Document' }} (removed)</p>
                        @endif
                        @break

                    @case(\App\Models\Message::TYPE_LOCATION)
                        <a href="https://www.openstreetmap.org/?mlat={{ (float) ($meta['lat'] ?? 0) }}&amp;mlon={{ (float) ($meta['lng'] ?? 0) }}#map=16/{{ (float) ($meta['lat'] ?? 0) }}/{{ (float) ($meta['lng'] ?? 0) }}" target="_blank" rel="noopener noreferrer" class="admin-file">
                            <x-icon name="map-pin" />
                            <span class="admin-file-body">
                                <span class="admin-file-name">{{ ($meta['live'] ?? false) ? 'Live location' : 'Location' }}</span>
                                <span class="admin-file-meta tabular-nums">{{ number_format((float) ($meta['lat'] ?? 0), 5) }}, {{ number_format((float) ($meta['lng'] ?? 0), 5) }}</span>
                            </span>
                            <x-icon name="external-link" />
                        </a>
                        @break

                    @case(\App\Models\Message::TYPE_CONTACT)
                        <div class="admin-file">
                            <x-icon name="contact" />
                            <span class="admin-file-body">
                                <span class="admin-file-name">{{ $meta['name'] ?? 'Contact' }}</span>
                                <span class="admin-file-meta tabular-nums">{{ collect($meta['phones'] ?? [])->map(fn ($p) => is_array($p) ? ($p['number'] ?? '') : $p)->filter()->implode(', ') }}</span>
                            </span>
                        </div>
                        @break

                    @case(\App\Models\Message::TYPE_POLL)
                        <div class="admin-poll">
                            <strong><x-icon name="chart-column" /> {{ $meta['question'] ?? 'Poll' }}</strong>
                            @foreach ($meta['options'] ?? [] as $option)
                                @php($votes = $message->pollVotes->where('option', (int) $option['id'])->count())
                                <div class="admin-poll-option"><span>{{ $option['text'] ?? '' }}</span><span class="tabular-nums">{{ $votes }}</span></div>
                            @endforeach
                        </div>
                        @break

                    @case(\App\Models\Message::TYPE_CALL)
                        <p class="admin-bubble-muted"><x-icon name="phone" /> {{ $message->callPreview(outgoing: true) }}</p>
                        @break
                @endswitch

                @if ($message->message && ! in_array($type, [\App\Models\Message::TYPE_POLL, \App\Models\Message::TYPE_CONTACT, \App\Models\Message::TYPE_CALL], true))
                    <p class="admin-bubble-text">{!! nl2br(e($message->message)) !!}</p>
                @endif

                @if ($message->linkPreview)
                    <span class="admin-bubble-link"><x-icon name="link" /> {{ $message->linkPreview->title ?: $message->linkPreview->url }}</span>
                @endif
            @endif

            <div class="admin-bubble-foot">
                @if ($message->reactions->isNotEmpty())
                    <span class="admin-reactions" title="Reactions">{{ $message->reactions->groupBy('emoji')->map(fn ($r, $emoji) => $emoji.($r->count() > 1 ? ' '.$r->count() : ''))->implode('  ') }}</span>
                @endif
                @if ($message->is_edited && ! $deleted)
                    <span>Edited</span>
                @endif
                <time datetime="{{ $message->created_at?->toIso8601String() }}" title="{{ $message->created_at?->format('j M Y, g:i:s A') }}">{{ $message->created_at?->format('g:i A') }}</time>
                @if ($message->receiver_id)
                    <span title="{{ $message->seen_at ? 'Seen '.$message->seen_at->format('j M, g:i A') : ($message->delivered_at ? 'Delivered' : 'Sent') }}">
                        <x-icon :name="$message->delivered_at || $message->seen_at ? 'check-check' : 'check'" @class(['admin-tick', 'is-seen' => (bool) $message->seen_at]) />
                    </span>
                @endif
            </div>
        </div>

        @unless ($deleted)
            <form method="POST" action="{{ route('admin.messages.destroy', $message) }}" class="admin-msg-tools"
                  data-confirm="The message will be deleted for everyone in this chat. People will see &quot;This message was deleted&quot;." data-confirm-title="Delete this message?" data-confirm-label="Delete for everyone" data-confirm-danger>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-icon btn-icon-sm" aria-label="Delete message for everyone" title="Delete for everyone"><x-icon name="trash-2" /></button>
            </form>
        @endunless
    </article>
@endif
