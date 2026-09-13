<x-layouts.base title="Chats" body-class="overflow-hidden" :scripts="['resources/js/chat/index.js']">
    <div class="chat-app" data-chat-app data-view="{{ $initialConversationId ? 'chat' : 'list' }}">

        {{-- ================================================================
             Sidebar
             ================================================================ --}}
        <aside class="chat-sidebar" aria-label="Conversations">
            <header class="sidebar-header">
                <a href="{{ route('profile.edit') }}" class="sidebar-me" title="Profile settings">
                    <x-avatar :user="$user" size="md" status class="is-online" data-me-avatar />
                    <span class="min-w-0">
                        <span class="sidebar-me-name">{{ $user->name }}</span>
                        <span class="sidebar-me-status" data-connection-status>
                            <span class="connection-dot"></span>
                            <span data-connection-label>Online</span>
                        </span>
                    </span>
                </a>

                <div class="flex items-center">
                    <x-theme-toggle />

                    {{-- Notification centre --}}
                    <div class="dropdown notif-dropdown">
                        <button type="button" class="btn-icon notif-bell" data-dropdown-toggle data-notifications-toggle
                                aria-haspopup="menu" aria-expanded="false" aria-label="Notifications" title="Notifications">
                            <x-icon name="bell" />
                            <span class="badge badge-primary notif-count" data-notifications-count hidden>0</span>
                        </button>
                        <div class="dropdown-menu notifications-menu" data-align="right" role="menu" hidden>
                            <div class="notifications-head">
                                <span class="font-bold text-sm">Notifications</span>
                                <button type="button" class="notifications-read-all" data-notifications-read-all>Mark all as read</button>
                            </div>
                            <div class="notifications-list" data-notifications-list>
                                <div class="flex justify-center p-5 text-primary"><span class="spinner"></span></div>
                            </div>
                        </div>
                    </div>

                    <div class="dropdown">
                        <button type="button" class="btn-icon" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Menu">
                            <x-icon name="ellipsis-vertical" />
                        </button>
                        <div class="dropdown-menu" data-align="right" role="menu" hidden>
                            <button type="button" class="dropdown-item" data-action="new-chat" role="menuitem"><x-icon name="message-square-plus" /> New chat</button>
                            <a href="{{ route('profile.edit') }}" class="dropdown-item" role="menuitem"><x-icon name="settings" /> Settings</a>
                            <a href="{{ route('profile.edit', ['tab' => 'preferences']) }}" class="dropdown-item" role="menuitem"><x-icon name="bell" /> Notifications</a>
                            @if ($user->isAdmin() && Route::has('admin.dashboard'))
                                <a href="{{ route('admin.dashboard') }}" class="dropdown-item" role="menuitem"><x-icon name="layout-dashboard" /> Admin panel</a>
                            @endif
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route('logout') }}" data-logout-form>
                                @csrf
                                <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="log-out" /> Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <div class="sidebar-search">
                <div class="input-wrap">
                    <x-icon name="search" />
                    <input type="search" class="form-control search-input" placeholder="Search name, username, email or mobile"
                           autocomplete="off" spellcheck="false" maxlength="100" aria-label="Search users" data-search-input>
                    <button type="button" class="input-action" data-search-clear aria-label="Clear search" hidden><x-icon name="x" class="icon-sm" /></button>
                </div>
            </div>

            <div class="sidebar-filters" role="tablist" aria-label="Filter conversations" data-filters>
                <button type="button" class="chip is-active" data-filter="all" role="tab" aria-selected="true">All</button>
                <button type="button" class="chip" data-filter="unread" role="tab" aria-selected="false">
                    Unread <span class="badge badge-primary" data-total-unread hidden>0</span>
                </button>
            </div>

            <section class="online-strip" data-online-strip hidden>
                <div class="online-strip-title">
                    <span>Online now</span>
                    <span data-online-count></span>
                </div>
                <div class="online-strip-list" data-online-list></div>
            </section>

            <div class="sidebar-scroll" data-sidebar-scroll>
                {{-- Desktop notification permission prompt --}}
                <div class="notify-banner" data-notify-banner hidden>
                    <span class="notify-banner-icon"><x-icon name="bell" /></span>
                    <span class="flex-1 min-w-0">
                        <span class="block text-sm font-semibold">Never miss a message</span>
                        <span class="block text-xs text-muted">Turn on desktop notifications.</span>
                    </span>
                    <button type="button" class="btn btn-primary btn-sm" data-notify-enable>Enable</button>
                    <button type="button" class="btn-icon btn-icon-sm" data-notify-dismiss aria-label="Dismiss"><x-icon name="x" /></button>
                </div>

                {{-- Search results --}}
                <div data-search-results hidden></div>

                {{-- Recent chats --}}
                <div data-conversation-list aria-live="polite">
                    @for ($i = 0; $i < 7; $i++)
                        <div class="conversation-skeleton">
                            <div class="skeleton" style="width: 2.9rem; height: 2.9rem; border-radius: 999px"></div>
                            <div class="flex-1 flex flex-col gap-2">
                                <div class="skeleton" style="height: .8rem; width: {{ 40 + ($i * 7) % 35 }}%"></div>
                                <div class="skeleton" style="height: .7rem; width: {{ 55 + ($i * 11) % 35 }}%"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>
        </aside>

        {{-- ================================================================
             Main chat area
             ================================================================ --}}
        <main class="chat-main">
            {{-- Welcome / empty state --}}
            <div class="chat-welcome" data-chat-welcome @if ($initialConversationId) hidden @endif>
                <div>
                    <div class="chat-welcome-art"><x-icon name="message-circle" class="icon-xl" /></div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Welcome, {{ \Illuminate\Support\Str::before($user->name, ' ') }}</h1>
                    <p class="text-muted mt-2 max-w-sm mx-auto">
                        Pick a conversation from the list or search for someone to start a private chat.
                    </p>
                    <button type="button" class="btn btn-primary mt-6" data-action="new-chat">
                        <x-icon name="message-square-plus" /> Start a new chat
                    </button>
                    <p class="text-xs text-subtle mt-6 flex items-center justify-center gap-1.5">
                        <x-icon name="lock" class="icon-xs" /> Conversations are private between the two participants.
                    </p>
                </div>
            </div>

            {{-- Conversation panel --}}
            <section class="chat-panel" data-chat-panel @unless ($initialConversationId) hidden @endunless>
                <header class="chat-header">
                    <button type="button" class="btn-icon back-btn" data-action="back" aria-label="Back to conversations">
                        <x-icon name="arrow-left" />
                    </button>

                    <div class="chat-header-user" data-chat-header-user>
                        <div class="skeleton" style="width: 2.75rem; height: 2.75rem; border-radius: 999px"></div>
                        <div class="flex flex-col gap-1.5">
                            <div class="skeleton" style="width: 8rem; height: .85rem"></div>
                            <div class="skeleton" style="width: 5rem; height: .7rem"></div>
                        </div>
                    </div>

                    <div class="chat-header-actions">
                        <div class="dropdown">
                            <button type="button" class="btn-icon" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Conversation options">
                                <x-icon name="ellipsis-vertical" />
                            </button>
                            <div class="dropdown-menu" data-align="right" role="menu" hidden data-conversation-menu></div>
                        </div>
                    </div>
                </header>

                <div class="chat-messages" data-messages tabindex="0" aria-label="Messages">
                    <div class="older-loader" data-older-sentinel hidden><span class="spinner"></span></div>
                    <div class="message-list" data-message-list role="log" aria-live="polite"></div>
                    <div class="typing-row" data-typing-row hidden>
                        <div class="typing-bubble" aria-label="Typing"><span></span><span></span><span></span></div>
                    </div>
                </div>

                <button type="button" class="scroll-bottom-btn" data-scroll-bottom aria-label="Scroll to latest messages">
                    <x-icon name="arrow-down" />
                    <span class="badge badge-primary" data-scroll-unread hidden>0</span>
                </button>

                <div class="drop-overlay" data-drop-overlay hidden>
                    <div class="drop-overlay-inner">
                        <x-icon name="upload" class="icon-xl" />
                        <div class="font-bold mt-2">Drop to attach</div>
                        <div class="text-sm opacity-80">JPG, PNG, PDF, DOC or DOCX</div>
                    </div>
                </div>

                <footer class="chat-composer" data-composer>
                    <div class="composer-notice" data-composer-notice hidden></div>

                    <div class="composer-extras" data-composer-extras>
                        <div data-composer-context></div>
                        <div data-attachment-preview></div>
                    </div>

                    {{-- Voice note recorder / preview --}}
                    <div class="voice-panel" data-voice-panel hidden>
                        <button type="button" class="btn-icon" data-voice-cancel aria-label="Discard voice message" title="Discard">
                            <x-icon name="trash-2" />
                        </button>

                        <div class="voice-panel-recording" data-voice-recording>
                            <span class="rec-dot" aria-hidden="true"></span>
                            <span class="voice-panel-time" data-voice-timer>0:00</span>
                            <span class="voice-panel-bars" aria-hidden="true">
                                @for ($i = 0; $i < 24; $i++)<span style="animation-delay: -{{ ($i * 137) % 900 }}ms"></span>@endfor
                            </span>
                            <span class="text-xs text-muted hidden sm:inline">Recording…</span>
                        </div>

                        <div class="voice-panel-preview" data-voice-preview hidden></div>

                        <button type="button" class="btn-icon voice-stop" data-voice-stop aria-label="Stop recording" title="Stop">
                            <x-icon name="square" />
                        </button>
                        <button type="button" class="composer-send" data-voice-send aria-label="Send voice message" hidden>
                            <x-icon name="send-horizontal" />
                        </button>
                    </div>

                    <form class="composer-form is-empty" data-composer-form autocomplete="off">
                        <div class="composer-input-wrap">
                            <div class="dropdown emoji-dropdown">
                                <button type="button" class="btn-icon btn-icon-sm" data-emoji-toggle aria-label="Insert emoji" title="Emoji">
                                    <x-icon name="smile" />
                                </button>
                            </div>

                            <textarea class="composer-textarea" rows="1" placeholder="Type a message"
                                      maxlength="{{ config('chat.max_message_length') }}" aria-label="Message" data-composer-input></textarea>

                            <div class="composer-inline-actions" data-composer-inline-actions>
                                <button type="button" class="btn-icon btn-icon-sm" data-attach-button aria-label="Attach a file" title="Attach photo or document">
                                    <x-icon name="paperclip" />
                                </button>
                                <input type="file" class="sr-only" tabindex="-1" data-attach-input
                                       accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,image/jpeg,image/png,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                            </div>
                        </div>

                        <button type="submit" class="composer-send" data-composer-send aria-label="Send message" disabled>
                            <x-icon name="send-horizontal" />
                        </button>
                        <button type="button" class="composer-send composer-mic" data-voice-record aria-label="Record voice message" title="Record voice message">
                            <x-icon name="mic" />
                        </button>
                    </form>
                </footer>
            </section>
        </main>
    </div>

    <script type="application/json" id="chat-config">@json($chatConfig)</script>
</x-layouts.base>
