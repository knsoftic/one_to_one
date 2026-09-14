<x-layouts.base title="Chats" body-class="overflow-hidden" :scripts="['resources/js/chat/index.js']">
    <div class="chat-app" data-chat-app data-view="{{ $initialConversationId ? 'chat' : 'list' }}">

        {{-- ================================================================
             Sidebar
             ================================================================ --}}
        {{-- Navigation rail (desktop, like WhatsApp Web) --}}
        <nav class="app-rail" aria-label="Sections">
            <div class="app-rail-group">
                <button type="button" class="app-rail-item is-active" data-mobile-tab="chats" aria-label="Chats" title="Chats" aria-current="page">
                    <x-icon name="message-circle" />
                    <span class="badge badge-primary" data-mobile-unread hidden>0</span>
                </button>
                <button type="button" class="app-rail-item" data-mobile-tab="status" aria-label="Status" title="Status">
                    <x-icon name="circle-dashed" />
                    <span class="status-dot" data-status-dot hidden></span>
                </button>
                <button type="button" class="app-rail-item" data-mobile-tab="channels" aria-label="Channels" title="Channels">
                    <x-icon name="rss" />
                </button>
                <button type="button" class="app-rail-item" data-mobile-tab="communities" aria-label="Communities" title="Communities">
                    <x-icon name="users-round" />
                </button>
                <button type="button" class="app-rail-item" data-mobile-tab="calls" aria-label="Calls" title="Calls">
                    <x-icon name="phone" />
                    <span class="badge badge-danger" data-calls-badge hidden>0</span>
                </button>
            </div>
            <div class="app-rail-group">
                <button type="button" class="app-rail-item" data-mobile-tab="starred" aria-label="Starred messages" title="Starred messages">
                    <x-icon name="star" />
                </button>
                <x-theme-toggle class="app-rail-item" />
                <a href="{{ route('profile.edit') }}" class="app-rail-item" aria-label="Settings" title="Settings">
                    <x-icon name="settings" />
                </a>
                <span class="app-rail-divider" aria-hidden="true"></span>
                <a href="{{ route('profile.edit', ['tab' => 'profile']) }}" class="app-rail-me" aria-label="Profile" title="Profile">
                    <x-avatar :user="$user" size="sm" data-me-avatar />
                </a>
            </div>
        </nav>

        <aside class="chat-sidebar" aria-label="Conversations" data-sidebar data-mode="chats">
            <header class="sidebar-header">
                <div class="sidebar-heading">
                    <h1 class="sidebar-title"><span class="sidebar-title-web">Chats</span><span class="sidebar-title-app">{{ config('app.name') }}</span></h1>
                    <span class="sidebar-me-status" data-connection-status>
                        <span class="connection-dot"></span>
                        <span data-connection-label>Online</span>
                    </span>
                </div>

                <div class="sidebar-header-actions">
                    <button type="button" class="btn-icon header-new-chat" data-action="new-chat" aria-label="New chat" title="New chat">
                        <x-icon name="message-square-plus" />
                    </button>

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
                            <button type="button" class="dropdown-item" data-action="new-group" role="menuitem"><x-icon name="users" /> New group</button>
                            <button type="button" class="dropdown-item" data-action="new-community" role="menuitem"><x-icon name="users-round" /> New community</button>
                            <button type="button" class="dropdown-item" data-action="new-broadcast" role="menuitem"><x-icon name="megaphone" /> New broadcast</button>
                            <button type="button" class="dropdown-item" data-action="open-linked-devices" role="menuitem"><x-icon name="monitor" /> Linked devices</button>
                            <button type="button" class="dropdown-item" data-action="open-starred" role="menuitem"><x-icon name="star" /> Starred messages</button>
                            <button type="button" class="dropdown-item" data-action="open-qr-code" role="menuitem"><x-icon name="qr-code" /> QR code</button>
                            <a href="{{ route('profile.edit') }}" class="dropdown-item" role="menuitem"><x-icon name="settings" /> Settings</a>
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

            {{-- ======================= Chats view ======================= --}}
            <div class="sidebar-view" data-sidebar-view="chats">
            <div class="sidebar-search">
                <div class="input-wrap">
                    <x-icon name="search" />
                    <input type="search" class="form-control search-input" placeholder="Search" title="Search by name, username, email or mobile"
                           autocomplete="off" spellcheck="false" maxlength="100" aria-label="Search users" data-search-input>
                    <button type="button" class="input-action" data-search-clear aria-label="Clear search" hidden><x-icon name="x" class="icon-sm" /></button>
                </div>
            </div>

            <div class="sidebar-filters" role="tablist" aria-label="Filter conversations" data-filters>
                <button type="button" class="chip is-active" data-filter="all" role="tab" aria-selected="true">All</button>
                <button type="button" class="chip" data-filter="unread" role="tab" aria-selected="false">
                    Unread <span class="badge badge-primary" data-total-unread hidden>0</span>
                </button>
                <button type="button" class="chip" data-filter="favorites" role="tab" aria-selected="false">Favorites</button>
                <span class="sidebar-list-chips" data-list-chips></span>
                <button type="button" class="chip chip-add" data-action="new-chat-list" aria-label="New list" title="New list"><x-icon name="plus" /></button>
            </div>

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

            <button type="button" class="fab" data-action="new-chat" aria-label="New chat">
                <x-icon name="message-square-plus" />
            </button>
            </div>

            {{-- ======================= Contacts view (new chat) ======================= --}}
            <div class="sidebar-view contacts-view" data-sidebar-view="contacts" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-contacts" aria-label="Back to chats">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title">New chat</div>
                        <div class="contacts-subtitle" data-contacts-count>Contacts on {{ config('app.name') }}</div>
                    </div>
                </header>

                <div class="sidebar-search">
                    <div class="input-wrap">
                        <x-icon name="search" />
                        <input type="search" class="form-control search-input" placeholder="Search contacts, name or number"
                               autocomplete="off" spellcheck="false" maxlength="100" aria-label="Search contacts" data-contacts-search>
                    </div>
                </div>

                <div class="sidebar-scroll" data-contacts-scroll>
                    <div class="contacts-actions">
                        <button type="button" class="contacts-action" data-contacts-sync hidden>
                            <span class="contacts-action-icon"><x-icon name="users" /></span>
                            <span class="min-w-0">
                                <span class="contacts-action-title">Sync phone contacts</span>
                                <span class="contacts-action-text">Find people saved in your phone who use {{ config('app.name') }}</span>
                            </span>
                        </button>
                        <label class="contacts-action contacts-import-option" for="contacts-import-input">
                            <span class="contacts-action-icon is-muted"><x-icon name="upload" /></span>
                            <span class="min-w-0">
                                <span class="contacts-action-title">Import contacts file</span>
                                <span class="contacts-action-text">Upload a .vcf file exported from your phone's Contacts app</span>
                            </span>
                            <input id="contacts-import-input" type="file" accept=".vcf,text/vcard,text/x-vcard" class="sr-only" data-contacts-import>
                        </label>
                        <button type="button" class="contacts-action" data-invite-friends>
                            <span class="contacts-action-icon is-muted"><x-icon name="user-plus" /></span>
                            <span class="min-w-0">
                                <span class="contacts-action-title">Invite friends</span>
                                <span class="contacts-action-text">Send a link by WhatsApp, SMS or email</span>
                            </span>
                        </button>
                    </div>

                    <div data-contacts-results hidden></div>
                    <div data-contacts-list>
                        @for ($i = 0; $i < 4; $i++)
                            <div class="conversation-skeleton">
                                <div class="skeleton" style="width: 2.9rem; height: 2.9rem; border-radius: 999px"></div>
                                <div class="flex-1 flex flex-col gap-2">
                                    <div class="skeleton" style="height: .8rem; width: {{ 45 + ($i * 9) % 30 }}%"></div>
                                    <div class="skeleton" style="height: .7rem; width: 35%"></div>
                                </div>
                            </div>
                        @endfor
                    </div>
                </div>
            </div>

            {{-- ======================= Status view (Phase 5) ======================= --}}
            <div class="sidebar-view status-view" data-sidebar-view="status" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-status" aria-label="Back to chats">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title">Status</div>
                        <div class="contacts-subtitle">Updates disappear after 24 hours</div>
                    </div>
                    <button type="button" class="btn-icon" data-action="status-privacy" aria-label="Status privacy" title="Status privacy">
                        <x-icon name="lock" />
                    </button>
                </header>
                <div class="sidebar-scroll" data-status-body></div>
            </div>

            {{-- ======================= Channels view (G11) ======================= --}}
            <div class="sidebar-view channels-view" data-sidebar-view="channels" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-channels" aria-label="Back to chats">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title">Channels</div>
                        <div class="contacts-subtitle">Updates from people and places you follow</div>
                    </div>
                    <button type="button" class="btn-icon" data-action="new-channel" aria-label="New channel" title="New channel">
                        <x-icon name="plus" />
                    </button>
                </header>
                <div class="sidebar-search">
                    <div class="input-wrap">
                        <x-icon name="search" />
                        <input type="search" class="form-control search-input" placeholder="Search channels" autocomplete="off" spellcheck="false" maxlength="100" aria-label="Search channels" data-channels-search>
                    </div>
                </div>
                <div class="sidebar-scroll" data-channels-body></div>
            </div>

            {{-- ======================= Communities view (G10) ======================= --}}
            <div class="sidebar-view communities-view" data-sidebar-view="communities" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-communities" aria-label="Back">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title" data-communities-title>Communities</div>
                        <div class="contacts-subtitle">Groups under one roof</div>
                    </div>
                    <button type="button" class="btn-icon" data-action="new-community" aria-label="New community" title="New community">
                        <x-icon name="plus" />
                    </button>
                </header>
                <div class="sidebar-scroll" data-communities-body></div>
            </div>

            {{-- ======================= Calls view (K1) ======================= --}}
            <div class="sidebar-view calls-view" data-sidebar-view="calls" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-calls" aria-label="Back to chats">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title">Calls</div>
                        <div class="contacts-subtitle">Tap a call to open the chat</div>
                    </div>
                    <button type="button" class="btn-icon" data-action="call-links" aria-label="Call links" title="Call links">
                        <x-icon name="link-2" />
                    </button>
                    <button type="button" class="btn-icon" data-action="new-group-call" aria-label="New group call" title="New group call">
                        <x-icon name="users" />
                    </button>
                    <button type="button" class="btn-icon" data-action="clear-calls" aria-label="Clear call log" title="Clear call log">
                        <x-icon name="trash-2" />
                    </button>
                </header>
                <div class="sidebar-scroll" data-calls-scroll>
                    <div data-calls-list></div>
                    <div class="flex justify-center py-3" data-calls-more hidden><span class="spinner"></span></div>
                </div>
            </div>

            {{-- ======================= Starred messages view ======================= --}}
            <div class="sidebar-view starred-view" data-sidebar-view="starred" hidden>
                <header class="contacts-header">
                    <button type="button" class="btn-icon" data-action="close-starred" aria-label="Back to chats">
                        <x-icon name="arrow-left" />
                    </button>
                    <div class="min-w-0 flex-1">
                        <div class="contacts-title">Starred messages</div>
                        <div class="contacts-subtitle">Only you can see these</div>
                    </div>
                </header>
                <div class="sidebar-scroll" data-starred-scroll>
                    <div data-starred-list></div>
                    <div class="flex justify-center py-3" data-starred-more hidden><span class="spinner"></span></div>
                </div>
            </div>
        </aside>

        {{-- Mobile bottom navigation --}}
        <nav class="mobile-nav" aria-label="Main">
            <button type="button" class="mobile-nav-item is-active" data-mobile-tab="chats" aria-current="page">
                <span class="mobile-nav-icon"><x-icon name="message-circle" /><span class="badge badge-primary mobile-nav-badge" data-mobile-unread hidden>0</span></span>
                <span>Chats</span>
            </button>
            <button type="button" class="mobile-nav-item" data-mobile-tab="status">
                <span class="mobile-nav-icon"><x-icon name="circle-dashed" /><span class="status-dot" data-status-dot hidden></span></span>
                <span>Updates</span>
            </button>
            <button type="button" class="mobile-nav-item" data-mobile-tab="communities">
                <span class="mobile-nav-icon"><x-icon name="users-round" /></span>
                <span>Communities</span>
            </button>
            <button type="button" class="mobile-nav-item" data-mobile-tab="calls">
                <span class="mobile-nav-icon"><x-icon name="phone" /><span class="badge badge-danger mobile-nav-badge" data-calls-badge hidden>0</span></span>
                <span>Calls</span>
            </button>
        </nav>

        {{-- ================================================================
             Main chat area
             ================================================================ --}}
        <main class="chat-main">
            {{-- Welcome / empty state --}}
            <div class="chat-welcome" data-chat-welcome @if ($initialConversationId) hidden @endif>
                <div>
                    <div class="chat-welcome-art" aria-hidden="true">
                        <svg viewBox="0 0 320 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <ellipse cx="160" cy="176" rx="130" ry="14" fill="currentColor" opacity=".06"/>
                            <rect x="58" y="34" width="176" height="116" rx="10" fill="var(--c-surface)" stroke="var(--c-border-strong)" stroke-width="3"/>
                            <rect x="70" y="46" width="152" height="92" rx="4" fill="var(--c-chat-bg)"/>
                            <path d="M40 150h212a8 8 0 0 1-8 10H48a8 8 0 0 1-8-10z" fill="var(--c-border-strong)"/>
                            <rect x="80" y="58" width="70" height="16" rx="5" fill="var(--c-surface)"/>
                            <rect x="132" y="82" width="80" height="16" rx="5" fill="var(--c-bubble-out)"/>
                            <rect x="80" y="106" width="56" height="16" rx="5" fill="var(--c-surface)"/>
                            <rect x="226" y="72" width="62" height="104" rx="12" fill="var(--c-surface)" stroke="var(--c-border-strong)" stroke-width="3"/>
                            <rect x="234" y="86" width="46" height="74" rx="4" fill="var(--c-chat-bg)"/>
                            <rect x="239" y="94" width="28" height="10" rx="4" fill="var(--c-surface)"/>
                            <rect x="248" y="110" width="28" height="10" rx="4" fill="var(--c-bubble-out)"/>
                            <circle cx="257" cy="168" r="3" fill="var(--c-border-strong)"/>
                            <circle cx="262" cy="46" r="18" fill="var(--c-primary)"/>
                            <path d="M253 46l6 6 11-12" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h1 class="chat-welcome-title">{{ config('app.name') }} Web</h1>
                    <p class="chat-welcome-text">
                        Hi {{ \Illuminate\Support\Str::before($user->name, ' ') }}! Send and receive messages, make calls and share status updates right from your computer. Pick a chat on the left or start a new one.
                    </p>
                    <button type="button" class="btn btn-primary mt-6" data-action="new-chat">
                        <x-icon name="message-square-plus" /> Start a new chat
                    </button>
                    <p class="chat-welcome-foot">
                        <x-icon name="lock" class="icon-xs" /> Conversations are private between the people in them.
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
                        <button type="button" class="btn-icon chat-search-toggle" data-action="chat-search" aria-label="Search in chat" title="Search in chat">
                            <x-icon name="search" />
                        </button>
                        @if (config('chat.calls.enabled', true))
                            <button type="button" class="btn-icon" data-action="call-video" data-call-button data-call-label="Video call" aria-label="Video call" title="Video call" hidden>
                                <x-icon name="video" />
                            </button>
                            <button type="button" class="btn-icon" data-action="call-audio" data-call-button data-call-label="Voice call" aria-label="Voice call" title="Voice call" hidden>
                                <x-icon name="phone" />
                            </button>
                        @endif
                        <div class="dropdown">
                            <button type="button" class="btn-icon" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Conversation options">
                                <x-icon name="ellipsis-vertical" />
                            </button>
                            <div class="dropdown-menu" data-align="right" role="menu" hidden data-conversation-menu></div>
                        </div>
                    </div>

                    {{-- Search inside this chat (covers the header while open) --}}
                    <div class="chat-search" data-chat-search hidden>
                        <button type="button" class="btn-icon" data-chat-search-close aria-label="Close search">
                            <x-icon name="arrow-left" />
                        </button>
                        <input type="search" class="chat-search-input" data-chat-search-input placeholder="Search messages" aria-label="Search messages in this chat" autocomplete="off" maxlength="100" enterkeyhint="search">
                        <span class="chat-search-count" data-chat-search-count aria-live="polite"></span>
                        <button type="button" class="btn-icon" data-chat-search-older aria-label="Older match" disabled>
                            <x-icon name="chevron-up" />
                        </button>
                        <button type="button" class="btn-icon" data-chat-search-newer aria-label="Newer match" disabled>
                            <x-icon name="chevron-down" />
                        </button>
                        <div class="chat-search-results dropdown-menu" data-chat-search-results role="listbox" aria-label="Search results" hidden></div>
                    </div>
                </header>

                {{-- Pinned messages --}}
                <button type="button" class="pinned-bar" data-pinned-bar hidden>
                    <span class="pinned-bar-icon"><x-icon name="pin" /></span>
                    <span class="pinned-bar-body">
                        <span class="pinned-bar-label" data-pinned-label>Pinned message</span>
                        <span class="pinned-bar-text" data-pinned-text></span>
                    </span>
                    <span class="pinned-bar-dots" data-pinned-dots aria-hidden="true"></span>
                </button>

                <div class="chat-messages" data-messages tabindex="0" aria-label="Messages">
                    <div class="older-loader" data-older-sentinel hidden><span class="spinner"></span></div>
                    <div class="message-list" data-message-list role="log" aria-live="polite"></div>
                    <div class="typing-row" data-typing-row hidden>
                        <div class="typing-bubble" aria-label="Typing"><span></span><span></span><span></span><x-icon name="mic" class="typing-mic" /></div>
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
                        <div data-link-preview></div>
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
                        <button type="button" class="view-once-toggle" data-voice-once aria-pressed="false" title="Send as view once" hidden>1</button>
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
                                <button type="button" class="btn-icon btn-icon-sm" data-attach-button aria-label="Attach a file" title="Attach photos, videos or files">
                                    <x-icon name="paperclip" />
                                </button>
                                <button type="button" class="btn-icon btn-icon-sm" data-camera-button aria-label="Open camera" title="Camera" hidden>
                                    <x-icon name="camera" />
                                </button>
                                <input type="file" class="sr-only" tabindex="-1" data-attach-input multiple
                                       accept="{{ collect(config('chat.uploads.image.extensions'))->merge(config('chat.uploads.video.extensions'))->merge(config('chat.uploads.document.extensions'))->map(fn ($extension) => '.'.$extension)->implode(',') }},image/jpeg,image/png,video/*">
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
