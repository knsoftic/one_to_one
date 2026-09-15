import axios from '../bootstrap';
import { $, debounce, errorMessage, throttle } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { MessageActions } from './actions';
import { createApi } from './api';
import { AttachmentComposer } from './attachments';
import { BlockManager } from './blocks';
import { CallManager } from './calls';
import { ContactsPanel } from './contacts';
import { Notifier } from './notifications';
import { EmojiPicker } from './emoji';
import { GifPanel, StickerPanel } from './stickers';
import { ForwardDialog } from './forward';
import { Reactions } from './reactions';
import { ChatSearch } from './search';
import { StarredMessages } from './starred';
import { PinnedMessages } from './pins';
import { albumLayout } from './album';
import { LinkPreviewComposer } from './link-preview';
import { ChatListActions, chatListOrder, hasUnread, lockedChats, unreadTotal } from './chat-list';
import { ChatLock } from './chat-lock';
import { CallLinks } from './call-links';
import { CallLog } from './call-log';
import { ChatLists, matchesFilter } from './chat-lists';
import { GroupInvites } from './group-invite';
import { Broadcasts, broadcastName } from './broadcasts';
import { Communities } from './communities';
import { Channels, channelAvatar, followersLabel, keepMyChoices } from './channels';
import { Statuses } from './status';
import { ContactInfo } from './contact-info';
import { ReportUser } from './report';
import { LinkedDevices } from './linked-devices';
import { ProfileQr } from './profile-qr';
import { Groups, groupSummary } from './groups';
import { InviteFriends } from './invite';
import { LocationSharing } from './location';
import { Mentions } from './mentions';
import { ContactSharing } from './contact-share';
import { Polls } from './poll';
import { DisappearingMessages } from './disappearing';
import { ViewOnce } from './view-once';
import { DraftStore } from './drafts';
import { dayKey, formatLastSeen } from './format';
import { openLightbox } from './lightbox';
import { MediaGallery } from './media-gallery';
import { AutoDownload } from './auto-download';
import { ChatWallpaper } from './chat-wallpaper';
import { ChatTone } from './chat-tone';
import { ChatExport } from './chat-export';
import { KeyboardShortcuts } from './shortcuts';
import { openVideoPlayer } from './video';
import { Realtime } from './realtime';
import * as T from './templates';
import { VoiceRecorder, bindVoicePlayers } from './voice';

const STATUS_RANK = { failed: 0, pending: 0, sent: 1, delivered: 2, seen: 3 };

const GROUP_WINDOW_MS = 5 * 60 * 1000;
const NEAR_BOTTOM_PX = 160;
const isTouchDevice = () => window.matchMedia('(pointer: coarse)').matches;
const uuid = () =>
    window.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

const { previewOf } = T;

/** Natural size of an image URL (used to reserve space before upload completes). */
function imageSize(url) {
    return new Promise((resolve) => {
        if (!url) return resolve({});
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => resolve({});
        img.src = url;
    });
}

export class ChatApp {
    constructor(config) {
        this.config = config;
        this.me = config.user;
        this.api = createApi(config.routes);

        /** @type {Map<number, object>} */
        this.conversations = new Map();
        /** @type {Map<number, object>} latest known public profile / presence per user */
        this.users = new Map();
        this.pending = new Map();
        this.typingConversations = new Set();
        /** @type {Map<number, 'typing'|'recording'>} what the other person is doing */
        this.typingActions = new Map();
        /** @type {Map<number, number>} who is typing in a group */
        this.typingUsers = new Map();
        /** @type {Map<number, string>} names saved in the user's phone book */
        this.savedNames = new Map();
        this.typingTimers = new Map();
        this.deliveredQueue = new Set();
        this.statusCache = new Map();
        this.typingSentAt = null;
        this.typingConversationId = null;
        this.markSeenSoon = debounce(() => this.markActiveSeen(), 250);
        this.drafts = new DraftStore(this.me.id);
        this.saveDraftSoon = debounce(() => this.saveDraft(), 400);
        /** Album ids opened with "+N". */
        this.expandedAlbums = new Set();
        /** File uploads run one after another, so messages keep the order they were picked in. */
        this.uploadChain = Promise.resolve();
        this.fileChain = Promise.resolve();

        this.filter = 'all';
        /** 'chats' or 'archived' (Phase 2). */
        this.listMode = 'chats';
        this.searchAbort = null;
        this.active = null;
        this.openToken = 0;
        this.conversationsLoaded = false;

        const q = (selector) => $(selector);
        this.el = {
            app: q('[data-chat-app]'),
            sidebarScroll: q('[data-sidebar-scroll]'),
            conversationList: q('[data-conversation-list]'),
            searchInput: q('[data-search-input]'),
            searchClear: q('[data-search-clear]'),
            searchResults: q('[data-search-results]'),
            filters: q('[data-filters]'),
            totalUnread: q('[data-total-unread]'),
            welcome: q('[data-chat-welcome]'),
            panel: q('[data-chat-panel]'),
            headerUser: q('[data-chat-header-user]'),
            conversationMenu: q('[data-conversation-menu]'),
            messages: q('[data-messages]'),
            messageList: q('[data-message-list]'),
            olderSentinel: q('[data-older-sentinel]'),
            typingRow: q('[data-typing-row]'),
            scrollBottom: q('[data-scroll-bottom]'),
            scrollUnread: q('[data-scroll-unread]'),
            composer: q('[data-composer]'),
            composerForm: q('[data-composer-form]'),
            composerInput: q('[data-composer-input]'),
            composerSend: q('[data-composer-send]'),
            composerNotice: q('[data-composer-notice]'),
            composerExtras: q('[data-composer-extras]'),
            emojiToggle: q('[data-emoji-toggle]'),
            connectionStatus: q('[data-connection-status]'),
            connectionLabel: q('[data-connection-label]'),
        };

        this.baseTitle = document.title;
        this.stickerPanel = new StickerPanel(this);
        this.gifPanel = new GifPanel(this);
        this.emojiPicker = new EmojiPicker(this.el.composer, (emoji) => this.insertAtCursor(emoji), {
            modes: [
                ...(this.api.has('stickers') ? [this.stickerPanel.mode()] : []),
                ...(this.api.has('gifs') ? [this.gifPanel.mode()] : []),
            ],
        });

        this.autoDownload = new AutoDownload(() => this.me.auto_download);
        T.setTemplateContext({
            meId: this.me.id,
            nameOf: (userId) => this.displayName(userId, this.users.get(Number(userId))?.name ?? ''),
            autoDownload: (message) => this.autoDownload.allows(message),
        });
    }

    /* ================================================================== */
    /* Bootstrapping                                                       */
    /* ================================================================== */

    async init() {
        this.bindGlobalActions();
        this.bindSidebar();
        this.bindComposer();
        this.bindMessages();
        this.bindNavigation();

        this.actions = new MessageActions(this);
        this.attachments = new AttachmentComposer(this);
        this.voice = new VoiceRecorder(this);
        this.blocks = new BlockManager(this);
        this.notifier = new Notifier(this);
        this.invite = new InviteFriends(this);
        this.contactsPanel = new ContactsPanel(this);
        this.calls = new CallManager(this);
        this.forwardDialog = new ForwardDialog(this);
        this.reactions = new Reactions(this);
        this.chatSearch = new ChatSearch(this);
        this.starred = new StarredMessages(this);
        this.callLog = new CallLog(this);
        this.callLinks = new CallLinks(this);
        this.groups = new Groups(this);
        this.broadcasts = new Broadcasts(this);
        this.communities = new Communities(this);
        this.channels = new Channels(this);
        this.statuses = new Statuses(this);
        this.contactInfo = new ContactInfo(this);
        this.media = new MediaGallery(this);
        this.wallpaper = new ChatWallpaper(this);
        this.tone = new ChatTone(this);
        this.exports = new ChatExport(this);
        this.shortcuts = new KeyboardShortcuts(this);
        this.reports = new ReportUser(this);
        this.linkedDevices = new LinkedDevices(this);
        this.profileQr = new ProfileQr(this);
        this.groupInvites = new GroupInvites(this);
        this.mentions = new Mentions(this);
        this.pins = new PinnedMessages(this);
        this.linkPreviews = new LinkPreviewComposer(this);
        this.chatLock = new ChatLock(this);
        this.chatList = new ChatListActions(this);
        this.chatLists = new ChatLists(this);
        this.locationSharing = new LocationSharing(this);
        this.contactSharing = new ContactSharing(this);
        this.polls = new Polls(this);
        this.disappearing = new DisappearingMessages(this);
        this.viewOnce = new ViewOnce(this);
        if (this.api.has('messageVote')) {
            this.attachments.menu.add({ id: 'poll', icon: 'chart-column', label: 'Poll', run: () => this.polls.open() });
        }
        this.attachments.menu.add({ id: 'contact', icon: 'contact', label: 'Contact', run: () => this.contactSharing.open() });
        if (navigator.geolocation && this.api.has('messageLocation')) {
            this.attachments.menu.add({ id: 'location', icon: 'map-pin', label: 'Location', run: () => this.locationSharing.open() });
        }
        bindVoicePlayers(this.el.messageList);
        this.bindMobileNav();
        this.updateSendState();

        this.realtime = new Realtime(this);
        this.realtime.start();

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') this.markSeenSoon();
        });
        window.addEventListener('focus', () => this.markSeenSoon());

        const initialId = this.config.initialConversationId;
        if (initialId) this.openConversation(initialId, { navigation: 'replace' });

        await this.loadConversations();
        this.loadOnlineUsers();

        // Keep relative times ("Yesterday", "last seen …") and online list fresh.
        setInterval(() => {
            this.renderConversations();
            this.updateHeaderStatus();
        }, 60_000);
        setInterval(() => this.loadOnlineUsers(), 60_000);

        document.dispatchEvent(new CustomEvent('chat:ready', { detail: { chat: this } }));
    }

    bindGlobalActions() {
        this.el.app.addEventListener('click', (event) => {
            const action = event.target.closest('[data-action]')?.dataset.action;
            if (!action) return;

            switch (action) {
                case 'new-chat':
                    this.contactsPanel.open();
                    break;
                case 'back':
                case 'close-chat':
                    this.closeConversation();
                    break;
                case 'say-hi':
                    if (this.active) this.sendText(this.active.id, '👋');
                    break;
                default:
                    document.dispatchEvent(new CustomEvent('chat:action', { detail: { action, event, chat: this } }));
            }
        });
    }

    bindNavigation() {
        window.addEventListener('popstate', () => {
            const match = window.location.pathname.match(/\/chat\/(\d+)\/?$/);
            if (match) {
                this.openConversation(Number(match[1]), { navigation: 'none' });
            } else {
                this.closeConversation({ navigation: 'none' });
            }
        });

        // Android back button in the mobile app: step back inside the chat UI first.
        document.addEventListener('app:back', (event) => {
            if (this.contactsPanel?.isOpen) {
                this.contactsPanel.close();
            } else if (this.actions?.mode) {
                this.actions.clearMode({ restoreText: true });
            } else if (this.voice?.state && this.voice.state !== 'idle') {
                this.voice.discard();
            } else if (this.active) {
                if (this.active.pushed) history.back();
                else this.closeConversation({ navigation: 'replace' });
            } else if (this.el.searchInput.value) {
                this.clearSearch();
            } else {
                return;
            }
            event.preventDefault();
        });
    }

    /* ================================================================== */
    /* Sidebar: conversations, search, online users                        */
    /* ================================================================== */

    bindSidebar() {
        const { searchInput, searchClear, filters, conversationList, sidebarScroll } = this.el;
        const runSearch = debounce((term) => this.search(term), 300);

        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim();
            searchClear.hidden = term === '';
            if (!term) {
                runSearch.cancel();
                this.clearSearch();
                return;
            }
            this.showSearchLoading();
            runSearch(term);
        });

        searchInput.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.clearSearch();
        });

        searchClear.addEventListener('click', () => {
            this.clearSearch();
            searchInput.focus();
        });

        filters.addEventListener('click', (event) => {
            const button = event.target.closest('[data-filter]');
            if (!button) return;
            this.setFilter(button.dataset.filter);
        });

        const onPick = (event) => {
            const conversation = event.target.closest('[data-conversation-id]');
            if (conversation) {
                this.clearSearch();
                this.openConversation(Number(conversation.dataset.conversationId));
                return;
            }
            const user = event.target.closest('[data-start-user-id]');
            if (user) this.startConversationWith(Number(user.dataset.startUserId));
        };

        conversationList.addEventListener('click', onPick);
        sidebarScroll.addEventListener('click', (event) => {
            if (!conversationList.contains(event.target)) onPick(event);
        });
    }

    async loadConversations() {
        try {
            const list = await this.api.conversations();
            this.conversations.clear();
            list.forEach((conversation) => this.upsertConversation(conversation, { render: false }));
            this.conversationsLoaded = true;
            this.renderConversations();
            // The list may have been fetched before the open chat was marked as seen.
            if (this.active) this.markSeenSoon();
        } catch (error) {
            this.el.conversationList.innerHTML = T.emptyState({
                iconName: 'wifi-off',
                title: "Couldn't load your chats",
                text: errorMessage(error),
            });
        }
    }

    upsertConversation(conversation, { render = true } = {}) {
        const existing = this.conversations.get(conversation.id);
        const merged = existing ? { ...existing, ...conversation } : { ...conversation };

        // Groups (Phase 4): remember the names of the people in them.
        for (const member of merged.group?.members ?? []) {
            if (member.user) this.rememberUser(member.user);
        }

        if (merged.participant) {
            // Masked presence (blocked_me) must not overwrite live presence data.
            const { is_online: isOnline, last_seen: lastSeen, ...profile } = merged.participant;
            this.rememberUser(merged.blocked_me ? profile : { ...profile, is_online: isOnline, last_seen: lastSeen });
        }

        this.conversations.set(merged.id, merged);
        if (render) this.renderConversations();
        return merged;
    }

    rememberUser(user) {
        const known = this.users.get(user.id) ?? {};
        const merged = { ...known, ...user };
        this.users.set(user.id, merged);
        return merged;
    }

    /** Participant of a conversation, merged with the latest presence data. */
    /** Name to show for a user: as saved in the phone book, otherwise their profile name. */
    displayName(userId, fallback = '') {
        return this.savedNames.get(Number(userId)) ?? this.users.get(Number(userId))?.saved_name ?? fallback;
    }

    /** Copy of a user object using the phone-book name when one is saved. */
    decorate(user) {
        if (!user) return user;
        const saved = this.savedNames.get(Number(user.id));
        return saved ? { ...user, name: saved, profile_name: user.name } : user;
    }

    /** User merged with the latest known presence (keeps the profile name). */
    presenceOf(user) {
        return { ...user, ...(this.users.get(user.id) ?? {}), name: user.name };
    }

    /** Contacts loaded or synced: remember saved names and refresh the UI. */
    applyContacts(contacts) {
        this.savedNames = new Map(contacts.map((contact) => [Number(contact.user.id), contact.name]));
        contacts.forEach((contact) => this.rememberUser(contact.user));
        this.renderConversations();
        const conversation = this.activeConversation();
        if (conversation) this.renderHeader(conversation);
    }

    bindMobileNav() {
        const tabs = document.querySelectorAll('[data-mobile-tab]');

        const sections = {
            contacts: () => this.contactsPanel.open(),
            calls: () => this.callLog?.open(),
            status: () => this.statuses?.open(),
            channels: () => this.channels?.open(),
            communities: () => this.communities?.open(),
            starred: () => this.starred?.open(),
        };

        tabs.forEach((tab) =>
            tab.addEventListener('click', () => {
                const open = sections[tab.dataset.mobileTab];
                if (open) {
                    open();
                } else if (this.contactsPanel.isOpen) {
                    this.contactsPanel.close();
                } else if (this.starred?.isOpen) {
                    this.starred.close();
                } else if (this.callLog?.isOpen) {
                    this.callLog.close();
                } else if (this.communities?.isOpen) {
                    this.communities.close();
                } else if (this.channels?.isOpen) {
                    this.channels.close();
                } else if (this.statuses?.isOpen) {
                    this.statuses.close();
                }
            }),
        );

        document.addEventListener('chat:sidebar-mode', (event) => {
            tabs.forEach((tab) => {
                const active = tab.dataset.mobileTab === event.detail.mode;
                tab.classList.toggle('is-active', active);
                if (active) tab.setAttribute('aria-current', 'page');
                else tab.removeAttribute('aria-current');
            });
        });
    }

    participantOf(conversation) {
        // A broadcast list (G9): its name and a megaphone.
        if (conversation?.type === 'broadcast' && !conversation.is_locked_out) {
            return { id: `broadcast-${conversation.id}`, name: broadcastName(conversation.broadcast), avatar_icon: 'megaphone', is_group: true, is_online: false, last_seen: null };
        }
        // A channel (G11): its name and icon.
        if (conversation?.type === 'channel' && !conversation.is_locked_out) {
            return channelAvatar(conversation.id, conversation.channel);
        }
        // A group (Phase 4) is shown with its own name and icon.
        if (conversation?.type === 'group' && !conversation.is_locked_out) {
            const group = conversation.group ?? {};
            return {
                id: `group-${conversation.id}`,
                name: group.name ?? 'Group',
                avatar_url: group.avatar_url ?? null,
                initials: group.initials ?? '#',
                avatar_hue: group.avatar_hue ?? 0,
                is_group: true,
                is_online: false,
                last_seen: null,
            };
        }
        const participant = conversation?.participant;
        if (!participant) return null;
        // "Message yourself" (C7): your own name, no presence.
        if (conversation.is_self) {
            const me = { ...participant, ...(this.users.get(participant.id) ?? {}) };
            return { ...me, name: `${me.name} (You)`, saved_name: null, is_online: false, last_seen: null, is_self: true };
        }
        const saved = this.savedNames.get(Number(participant.id)) ?? participant.saved_name;
        const merged = {
            ...participant,
            ...(this.users.get(participant.id) ?? {}),
            ...(saved ? { name: saved, profile_name: participant.name } : {}),
        };
        // Someone who blocked you does not share their presence.
        return conversation.blocked_me ? { ...merged, is_online: false, last_seen: null } : merged;
    }

    /** Ids of users who have blocked the current user (presence hidden). */
    blockedMeIds() {
        return new Set([...this.conversations.values()].filter((c) => c.blocked_me && c.participant).map((c) => c.participant.id));
    }

    /** Recent chats (archived included, deleted ones left out), newest first. */
    sortedConversations() {
        const time = (c) => new Date(c.last_message.created_at).getTime() || 0;

        // Locked chats (C9) stay out of search, forwarding and list pickers.
        return [...this.conversations.values()]
            .filter((c) => c.last_message && !c.settings?.hidden && !c.settings?.locked)
            .sort((a, b) => time(b) - time(a) || (b.last_message.id ?? 0) - (a.last_message.id ?? 0));
    }

    /** All, Unread, Favorites or one of my lists (C6). */
    setFilter(filter) {
        this.filter = filter;
        this.el.filters.querySelectorAll('[data-filter]').forEach((b) => {
            const active = b.dataset.filter === filter;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.renderConversations();
    }

    setListMode(mode) {
        this.listMode = mode;
        this.el.filters.hidden = mode !== 'chats';
        this.renderConversations();
        this.el.sidebarScroll.scrollTop = 0;
    }

    /** A chat deleted for me (here or on another device). */
    forgetConversation(id) {
        this.conversations.delete(Number(id));
        if (this.active?.id === Number(id)) this.closeConversation();
        this.renderConversations();
    }

    renderConversations() {
        if (!this.conversationsLoaded) return;

        const values = [...this.conversations.values()];
        const lockedMode = this.listMode === 'locked';
        const archivedMode = this.listMode === 'archived' || lockedMode;
        const all = chatListOrder(values, this.listMode);
        const visible = archivedMode ? all : all.filter((c) => matchesFilter(c, this.filter, this.chatLists?.lists));
        const list = this.el.conversationList;

        // "Locked chats" (C9) and "Archived" folders at the top of the main list; a back row inside them.
        const archived = archivedMode ? [] : chatListOrder(values, 'archived');
        const locked = archivedMode ? [] : lockedChats(values);
        const archivedUnread = archived.filter(hasUnread).length;
        const header = lockedMode
            ? T.lockedHeader()
            : archivedMode
              ? T.archivedHeader()
              : this.filter === 'all'
                ? (locked.length ? T.lockedRow(locked.length, locked.filter(hasUnread).length) : '') + (archived.length ? T.archivedRow(archived.length, archivedUnread) : '')
                : '';

        if (!all.length && lockedMode) {
            list.innerHTML = header + T.emptyState({ iconName: 'lock-keyhole', title: 'No locked chats', text: 'Open a chat menu and choose "Lock chat".' });
        } else if (!all.length && archivedMode) {
            list.innerHTML = header + T.emptyState({ iconName: 'archive', title: 'No archived chats', text: 'Archived chats stay here, even when new messages arrive.' });
        } else if (!all.length && !archived.length && !locked.length) {
            list.innerHTML = T.emptyState({
                iconName: 'message-square-plus',
                title: 'No conversations yet',
                text: 'Search for someone by name, username, email or mobile number to start chatting.',
            });
        } else if (!visible.length) {
            const empty = {
                unread: { iconName: 'check-check', title: 'All caught up', text: 'You have no unread messages.' },
                favorites: { iconName: 'heart', title: 'No favourites yet', text: 'Open a chat menu and choose "Add to Favorites".' },
            }[this.filter] ?? { iconName: 'tag', title: 'This list is empty', text: 'Right-click the list name to add chats.' };
            list.innerHTML = header + T.emptyState(empty);
        } else {
            list.innerHTML = header + visible
                .map((conversation) =>
                    T.conversationItem(
                        { ...conversation, participant: this.participantOf(conversation) },
                        {
                            active: conversation.id === this.active?.id,
                            typing: this.typingLabel(conversation),
                            draft: conversation.id === this.active?.id ? '' : this.drafts.get(conversation.id),
                        },
                    ),
                )
                .join('');
        }

        this.updateUnreadTotals();
    }

    /** "typing" / "recording", or in a group who is doing it. */
    typingLabel(conversation) {
        if (!this.typingConversations.has(conversation.id)) return false;
        const action = this.typingActions.get(conversation.id) ?? 'typing';
        if (conversation.type !== 'group') return action;
        const userId = this.typingUsers.get(conversation.id);
        return { action, name: userId ? this.displayName(userId, this.users.get(userId)?.name ?? '') : '' };
    }

    updateUnreadTotals() {
        // Muted and archived chats do not add to the badge or the page title (C2, C3).
        const total = unreadTotal(this.conversations.values());
        const badge = this.el.totalUnread;
        badge.hidden = total === 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        document.title = total > 0 ? `(${total}) ${this.baseTitle}` : this.baseTitle;

        document.querySelectorAll('[data-mobile-unread]').forEach((badge) => {
            badge.hidden = total === 0;
            badge.textContent = total > 99 ? '99+' : String(total);
        });
        document.dispatchEvent(new CustomEvent('chat:unread', { detail: { total } }));
    }

    bumpConversation(id) {
        const item = this.el.conversationList.querySelector(`[data-conversation-id="${id}"]`);
        if (!item) return;
        item.classList.remove('is-bumped');
        void item.offsetWidth; // restart animation
        item.classList.add('is-bumped');
    }

    showSearchLoading() {
        const { searchResults, conversationList, filters } = this.el;
        searchResults.hidden = false;
        conversationList.hidden = true;
        filters.hidden = true;
        if (!searchResults.innerHTML.trim()) {
            searchResults.innerHTML = '<div class="flex justify-center p-6 text-primary"><span class="spinner"></span></div>';
        }
    }

    clearSearch() {
        const { searchInput, searchClear, searchResults, conversationList, filters } = this.el;
        this.searchAbort?.abort();
        searchInput.value = '';
        searchClear.hidden = true;
        searchResults.hidden = true;
        searchResults.innerHTML = '';
        conversationList.hidden = false;
        filters.hidden = false;
    }

    async search(term) {
        this.searchAbort?.abort();
        const controller = new AbortController();
        this.searchAbort = controller;

        const needle = term.toLowerCase().replace(/^@/, '');
        const localMatches = this.sortedConversations().filter((c) => {
            const p = this.participantOf(c) ?? {};
            return `${p.name ?? ''} ${p.username ?? ''}`.toLowerCase().includes(needle);
        });

        try {
            const users = await this.api.searchUsers(term, controller.signal);
            if (controller.signal.aborted || this.el.searchInput.value.trim() !== term) return;

            const chats = localMatches
                .map((c) => T.conversationItem({ ...c, participant: this.participantOf(c) }, { active: c.id === this.active?.id }))
                .join('');

            users.forEach((user) => this.rememberUser(user));

            this.el.searchResults.innerHTML =
                (chats ? T.sectionTitle('Chats') + chats : '') +
                T.sectionTitle('People') +
                (users.length
                    ? users.map((user) => T.searchResultItem(this.decorate(user))).join('')
                    : T.emptyState({ iconName: 'search', title: 'No users found', text: 'Try a different name, username, email or mobile number.' }));
        } catch (error) {
            if (axios.isCancel(error) || error?.name === 'CanceledError') return;
            this.el.searchResults.innerHTML = T.emptyState({ iconName: 'circle-alert', title: 'Search failed', text: errorMessage(error) });
        }
    }

    async startConversationWith(userId) {
        if (userId === this.me.id) return;
        try {
            const conversation = await this.api.startConversation(userId);
            this.upsertConversation(conversation, { render: false });
            this.clearSearch();
            this.openConversation(conversation.id);
        } catch (error) {
            toast.error(errorMessage(error, 'Could not open this conversation.'));
        }
    }

    /** Presence of everyone online, for the green dots in the chat list. */
    async loadOnlineUsers() {
        try {
            const users = await this.api.onlineUsers();
            users.forEach((user) => this.rememberUser(user));
            this.refreshPresenceViews();
        } catch {
            /* non-critical */
        }
    }

    /* ================================================================== */
    /* Conversation panel                                                  */
    /* ================================================================== */

    showChatView() {
        this.el.app.dataset.view = 'chat';
    }

    showListView() {
        this.el.app.dataset.view = 'list';
    }

    chatUrl(id) {
        return this.config.routes.chatShow.replace('__ID__', id);
    }

    async openConversation(id, { navigation = 'push' } = {}) {
        id = Number(id);

        if (this.active?.id === id) {
            this.showChatView();
            if (!isTouchDevice()) this.el.composerInput.focus();
            return;
        }

        this.saveDraft();
        const token = ++this.openToken;
        this.emojiPicker.close();
        this.stopTyping();

        this.active = {
            id,
            messages: [],
            byId: new Map(),
            hasMore: false,
            loaded: false,
            loadingOlder: false,
            unseenBelow: 0,
            pushed: navigation === 'push',
        };

        this.el.welcome.hidden = true;
        this.el.panel.hidden = false;
        this.showChatView();

        if (navigation === 'push') history.pushState({ conversationId: id }, '', this.chatUrl(id));
        if (navigation === 'replace') history.replaceState({ conversationId: id }, '', this.chatUrl(id));

        this.el.messageList.innerHTML = T.messageSkeletons();
        this.el.olderSentinel.hidden = true;
        this.el.typingRow.hidden = true;
        this.el.composerInput.value = this.drafts.get(id);
        this.autosize();
        this.updateSendState();
        this.updateScrollButton(true);

        const known = this.conversations.get(id);
        if (known) this.renderHeader(known);
        this.renderConversations();

        try {
            const [conversation, page] = await Promise.all([this.api.conversation(id), this.api.messages(id)]);
            if (token !== this.openToken) return;

            const merged = this.upsertConversation(conversation, { render: false });
            this.renderHeader(merged);
            this.updateComposerState(merged);

            this.active.hasMore = page.has_more;
            this.active.loaded = true;
            this.renderMessages(page.data.map((m) => this.withCachedStatus(this.normalizeMessage(m))));
            this.renderTypingRow();
            this.scrollToBottom(false);
            this.renderConversations();
            this.markSeenSoon();

            if (!isTouchDevice()) this.el.composerInput.focus({ preventScroll: true });

            document.dispatchEvent(new CustomEvent('chat:opened', { detail: { conversation: merged, chat: this } }));
        } catch (error) {
            if (token !== this.openToken) return;
            const status = error?.response?.status;
            // A locked chat (C9): ask for the secret code, then open it again.
            if (status === 423) {
                this.active = null;
                if (await this.chatLock.unlock()) {
                    this.setListMode('locked');
                    this.openConversation(id, { navigation: 'replace' });
                } else {
                    this.closeConversation({ navigation: 'replace' });
                }
                return;
            }
            if (status === 404 || status === 403) {
                toast.error('This conversation is not available.');
                this.closeConversation({ navigation: 'replace' });
                return;
            }
            this.el.messageList.innerHTML = T.emptyState({ iconName: 'wifi-off', title: "Couldn't load messages", text: errorMessage(error) });
        }
    }

    closeConversation({ navigation = 'push' } = {}) {
        this.saveDraft();
        this.openToken++;
        this.emojiPicker.close();
        this.stopTyping();
        const wasOpen = Boolean(this.active);
        this.active = null;
        this.el.panel.hidden = true;
        this.el.welcome.hidden = false;
        this.showListView();

        if (navigation === 'push' && wasOpen) history.pushState({}, '', this.config.routes.chat);
        if (navigation === 'replace') history.replaceState({}, '', this.config.routes.chat);

        this.renderConversations();
        document.dispatchEvent(new CustomEvent('chat:closed', { detail: { chat: this } }));
    }

    activeConversation() {
        return this.active ? this.conversations.get(this.active.id) : null;
    }

    renderHeader(conversation) {
        const user = this.participantOf(conversation);
        if (!user) return;
        this.el.headerUser.innerHTML = T.chatHeaderUser(user);
        this.el.conversationMenu.innerHTML = this.conversationMenuItems(conversation);
        this.updateHeaderStatus();
        document.dispatchEvent(new CustomEvent('chat:header', { detail: { conversation, chat: this } }));
    }

    /** Overridable/extendable menu (block actions are added in later phases). */
    conversationMenuItems(conversation) {
        const items = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation, items, chat: this } }));
        items.push(`<button type="button" class="dropdown-item" data-action="close-chat" role="menuitem">${icon('x')} Close chat</button>`);
        return items.join('');
    }

    updateHeaderStatus() {
        if (!this.active) return;
        const conversation = this.activeConversation();
        const status = this.el.headerUser.querySelector('[data-chat-status]');
        const avatarEl = this.el.headerUser.querySelector('.avatar');
        if (!conversation || !status) return;

        const user = this.participantOf(conversation);
        const typing = this.typingConversations.has(conversation.id);
        const hidePresence = conversation.blocked_by_me || conversation.blocked_me;

        // Broadcast lists: who gets the messages.
        if (conversation.type === 'broadcast') {
            status.classList.remove('is-typing', 'is-online');
            const names = (conversation.broadcast?.recipients ?? []).map((user) => this.displayName(user.id, user.saved_name || user.name));
            status.textContent = names.length ? names.join(', ') : broadcastName(conversation.broadcast);
            this.el.headerUser.classList.add('is-clickable');
            return;
        }

        // Channels: how many follow it.
        if (conversation.type === 'channel') {
            status.classList.remove('is-typing', 'is-online');
            status.textContent = `Channel · ${followersLabel(conversation.channel?.followers_count ?? 0)}`;
            this.el.headerUser.classList.add('is-clickable');
            return;
        }

        // Groups: who is typing, otherwise who is in the group.
        if (conversation.type === 'group') {
            status.classList.toggle('is-typing', typing);
            status.classList.remove('is-online');
            const label = this.typingLabel(conversation);
            status.textContent = typing && label?.name
                ? `${label.name} is ${label.action === 'recording' ? 'recording audio' : 'typing'}…`
                : groupSummary(conversation.group, this.me.id, (id, name) => this.displayName(id, name));
            this.el.headerUser.classList.add('is-clickable');
            return;
        }
        // One-to-one chats open Contact info (Phase 6).
        this.el.headerUser.classList.toggle('is-clickable', !conversation.is_self);

        status.classList.toggle('is-typing', typing);
        status.classList.toggle('is-online', !typing && !hidePresence && Boolean(user?.is_online));
        avatarEl?.classList.toggle('is-online', !hidePresence && Boolean(user?.is_online));

        if (conversation.is_self) {
            status.textContent = 'Message yourself';
        } else if (typing) {
            status.textContent = this.typingActions.get(conversation.id) === 'recording' ? 'recording audio…' : 'typing…';
        } else if (hidePresence) {
            status.textContent = conversation.blocked_by_me ? 'You blocked this user' : '';
        } else if (user?.is_online) {
            status.textContent = 'online';
        } else {
            status.textContent = formatLastSeen(user?.last_seen);
        }
    }

    updateComposerState(conversation) {
        const { composer, composerNotice } = this.el;
        const group = conversation?.type === 'group' ? conversation.group : null;
        const channel = conversation?.type === 'channel' ? conversation.channel : null;
        const notice = channel
            ? (channel.can_send ? null : { text: 'Only channel admins can post updates. React to show what you think.', action: null })
            : group && !group.is_member
            ? { text: group.ended ? 'This group was deleted. Nobody can send messages to it any more.' : "You can't send messages to this group because you're no longer a member.", action: null }
            : group && !group.can_send
              ? { text: 'Only admins can send messages to this group.', action: null }
              : group
                ? null
                : conversation?.blocked_by_me
            ? { text: 'You blocked this user. Unblock to send messages.', action: 'unblock' }
            : conversation?.blocked_me
              ? { text: "You can't send messages to this user.", action: null }
              : null;

        composer.classList.toggle('is-disabled', Boolean(notice));
        composerNotice.hidden = !notice;
        composerNotice.innerHTML = notice
            ? `<span>${notice.text.replace(/</g, '&lt;')}</span>${
                  notice.action === 'unblock' ? '<button type="button" class="btn btn-secondary btn-sm" data-action="unblock">Unblock</button>' : ''
              }`
            : '';

        if (notice) this.emojiPicker.close();
        this.calls?.updateHeader(conversation);
    }

    /* ================================================================== */
    /* Messages                                                            */
    /* ================================================================== */

    bindMessages() {
        const { messages, messageList, scrollBottom, olderSentinel } = this.el;

        this.olderObserver = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) this.loadOlder();
            },
            { root: messages, rootMargin: '300px 0px 0px 0px' },
        );
        this.olderObserver.observe(olderSentinel);

        messages.addEventListener(
            'scroll',
            throttle(() => {
                if (this.isNearBottom()) {
                    this.active && (this.active.unseenBelow = 0);
                    this.updateScrollButton(true);
                    document.dispatchEvent(new CustomEvent('chat:reached-bottom', { detail: { chat: this } }));
                } else {
                    this.updateScrollButton();
                }
                if (this.active?.hasMore && messages.scrollTop < 200) this.loadOlder();
            }, 120),
            { passive: true },
        );

        scrollBottom.addEventListener('click', () => this.scrollToBottom(true));

        messageList.addEventListener('click', (event) => {
            // "+N" over the fourth photo of an album (drawn on the bubble): show the whole album.
            const albumMore = event.target.closest('.message.album-more');
            if (albumMore && (event.target === albumMore || event.target.classList.contains('message-bubble'))) {
                this.expandedAlbums.add(albumMore.dataset.album);
                this.applyAlbums();
                return;
            }

            const contactChat = event.target.closest('[data-contact-message]');
            if (contactChat) {
                this.startConversationWith(Number(contactChat.dataset.contactMessage));
                return;
            }

            const retry = event.target.closest('[data-retry]');
            if (retry) {
                const pending = this.pending.get(retry.dataset.retry);
                if (!pending) return;
                if (pending.kind === 'file') {
                    this.sendFile(pending.conversationId, { ...pending.options, retryClientId: retry.dataset.retry });
                } else if (pending.kind === 'special') {
                    this.sendSpecial(pending.conversationId, { ...pending.options, retryClientId: retry.dataset.retry });
                } else {
                    this.sendText(pending.conversationId, pending.text, retry.dataset.retry);
                }
                return;
            }

            // D5: download a photo, GIF or sticker that waited for a tap.
            const held = event.target.closest('[data-media-load]');
            if (held) {
                this.autoDownload.markLoaded(held.dataset.mediaLoad);
                this.updateMessage({ id: this.active?.byId.get(String(held.dataset.mediaLoad))?.id ?? held.dataset.mediaLoad, media_loaded: true });
                return;
            }

            const image = event.target.closest('[data-lightbox]');
            if (image) {
                openLightbox({
                    src: image.dataset.lightbox,
                    name: image.dataset.lightboxName,
                    download: image.dataset.lightboxDownload,
                });
            }

            const video = event.target.closest('[data-video]');
            if (video) {
                const messageId = video.closest('[data-message-id]')?.dataset.messageId;
                if (messageId) this.autoDownload.markLoaded(messageId);
                openVideoPlayer({
                    src: video.dataset.video,
                    name: video.dataset.videoName,
                    download: video.dataset.videoDownload,
                    poster: video.dataset.videoPoster,
                });
            }
        });
    }

    messageEl(id) {
        return this.el.messageList.querySelector(`[data-message-id="${CSS.escape(String(id))}"]`);
    }

    indexOfMessage(id) {
        return this.active ? this.active.messages.findIndex((m) => String(m.id) === String(id)) : -1;
    }

    isNearBottom() {
        const el = this.el.messages;
        return el.scrollHeight - el.scrollTop - el.clientHeight < NEAR_BOTTOM_PX;
    }

    scrollToBottom(smooth = true) {
        const el = this.el.messages;
        el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
        if (this.active) this.active.unseenBelow = 0;
        this.updateScrollButton(true);
    }

    updateScrollButton(forceHide = false) {
        const { scrollBottom, scrollUnread } = this.el;
        const show = !forceHide && this.active && !this.isNearBottom();
        scrollBottom.classList.toggle('is-visible', Boolean(show));
        const unseen = this.active?.unseenBelow ?? 0;
        scrollUnread.hidden = !show || unseen === 0;
        scrollUnread.textContent = unseen > 99 ? '99+' : String(unseen);
    }

    sameGroup(a, b) {
        return (
            a &&
            b &&
            a.sender_id === b.sender_id &&
            dayKey(a.created_at) === dayKey(b.created_at) &&
            Math.abs(new Date(b.created_at) - new Date(a.created_at)) < GROUP_WINDOW_MS
        );
    }

    /** Recompute "grouped" and "tail" classes for the given message indexes (all when omitted). */
    applyGrouping(indexes = null) {
        const list = this.active?.messages ?? [];
        const targets = indexes ?? list.map((_, i) => i);

        for (const i of targets) {
            const message = list[i];
            if (!message) continue;
            const el = this.messageEl(message.id);
            if (!el) continue;
            el.classList.toggle('is-grouped', this.sameGroup(list[i - 1], message));
            el.classList.toggle('has-tail', !this.sameGroup(message, list[i + 1]));
        }

        this.applyAlbums();
    }

    /** Lay out photos/videos sent together as albums (whole list: one change affects the run). */
    applyAlbums() {
        const layout = albumLayout(this.active?.messages ?? [], this.expandedAlbums);

        for (const el of this.el.messageList.querySelectorAll('.message[data-message-id]')) {
            const item = layout.get(el.dataset.messageId);
            el.classList.toggle('in-album', Boolean(item));
            el.classList.toggle('album-odd', Boolean(item) && item.position % 2 === 0);
            el.classList.toggle('album-even', Boolean(item) && item.position % 2 === 1);
            el.classList.toggle('album-first-row', Boolean(item) && item.position < 2);
            el.classList.toggle('album-last', Boolean(item?.last));
            el.classList.toggle('album-hidden', Boolean(item?.hidden));
            el.classList.toggle('album-more', Boolean(item?.more));

            if (item?.more) {
                el.style.setProperty('--album-more', JSON.stringify(`+${item.more}`));
                el.dataset.album = item.album;
            } else {
                el.style.removeProperty('--album-more');
            }
        }
    }

    renderMessages(messages) {
        const active = this.active;
        active.messages = [];
        active.byId.clear();

        if (!messages.length) {
            const conversation = this.activeConversation();
            const user = this.participantOf(conversation);
            this.el.messageList.innerHTML = T.historyStart(conversation?.type) + (user && !['group', 'broadcast', 'channel'].includes(conversation?.type) ? T.conversationIntro(user) : '');
            this.el.olderSentinel.hidden = true;
            return;
        }

        const parts = active.hasMore ? [] : [T.historyStart(this.activeConversation()?.type)];
        let previous = null;

        for (const message of messages) {
            if (!previous || dayKey(previous.created_at) !== dayKey(message.created_at)) {
                parts.push(T.dateDivider(message.created_at));
            }
            parts.push(this.renderBubble(message));
            active.messages.push(message);
            active.byId.set(String(message.id), message);
            previous = message;
        }

        this.el.messageList.innerHTML = parts.join('');
        this.el.olderSentinel.hidden = !active.hasMore;
        this.applyGrouping();
    }

    /** Hook point so later phases can decorate bubbles (reply, attachments, actions). */
    renderBubble(message) {
        // Group chats show who wrote each message (hidden on follow-up bubbles by CSS).
        const conversation = this.conversations.get(Number(message.conversation_id));
        if (conversation?.type === 'group' && !message.is_mine && !['system', 'call'].includes(message.type)) {
            const user = this.users.get(Number(message.sender_id)) ?? {};
            return T.messageBubble({
                ...message,
                sender_label: this.displayName(message.sender_id, user.name ?? message.sender_name ?? 'Someone'),
                sender_hue: user.avatar_hue ?? (Number(message.sender_id) * 47) % 360,
            });
        }
        return T.messageBubble(message);
    }

    async loadOlder(limit = null) {
        const active = this.active;
        if (!active || !active.loaded || !active.hasMore || active.loadingOlder) return;

        const oldest = active.messages.find((m) => typeof m.id === 'number');
        if (!oldest) return;

        active.loadingOlder = true;
        const token = this.openToken;

        try {
            const page = await this.api.messages(active.id, oldest.id, limit);
            if (token !== this.openToken) return;
            this.prependMessages(page.data, page.has_more);
        } catch (error) {
            if (token === this.openToken) toast.error(errorMessage(error, 'Could not load older messages.'));
        } finally {
            active.loadingOlder = false;
        }
    }

    prependMessages(messages, hasMore) {
        const active = this.active;
        const container = this.el.messages;
        const list = this.el.messageList;
        const previousHeight = container.scrollHeight;
        const previousTop = container.scrollTop;

        active.hasMore = hasMore;
        const fresh = messages.filter((m) => !active.byId.has(String(m.id)));
        const firstExisting = active.messages[0];

        const parts = hasMore ? [] : [T.historyStart(this.activeConversation()?.type)];
        let previous = null;
        for (const message of fresh) {
            if (!previous || dayKey(previous.created_at) !== dayKey(message.created_at)) {
                parts.push(T.dateDivider(message.created_at));
            }
            parts.push(this.renderBubble(message));
            previous = message;
        }

        // The existing list starts with a divider; drop it when the day continues.
        if (previous && firstExisting && dayKey(previous.created_at) === dayKey(firstExisting.created_at)) {
            const firstNode = list.firstElementChild;
            if (firstNode?.matches('[data-date-divider]')) firstNode.remove();
        }

        list.insertAdjacentHTML('afterbegin', parts.join(''));
        active.messages = [...fresh, ...active.messages];
        fresh.forEach((m) => active.byId.set(String(m.id), m));
        this.el.olderSentinel.hidden = !hasMore;
        this.applyGrouping([...Array(fresh.length + 1).keys()]);

        container.scrollTop = container.scrollHeight - previousHeight + previousTop;
    }

    /**
     * Append a message to the open conversation. Returns true when it was added.
     */
    appendMessage(message, { animate = true } = {}) {
        const active = this.active;
        if (!active || !active.loaded || Number(message.conversation_id) !== active.id) return false;

        if (active.byId.has(String(message.id))) {
            this.updateMessage(message);
            return false;
        }

        const wasNearBottom = this.isNearBottom();
        const previous = active.messages[active.messages.length - 1];

        this.el.messageList.querySelector('[data-conversation-intro]')?.remove();

        let markup = '';
        if (!previous || dayKey(previous.created_at) !== dayKey(message.created_at)) {
            markup += T.dateDivider(message.created_at);
        }
        markup += this.renderBubble(message);
        this.el.messageList.insertAdjacentHTML('beforeend', markup);

        active.messages.push(message);
        active.byId.set(String(message.id), message);

        const el = this.messageEl(message.id);
        if (animate && el) {
            el.classList.add('is-enter');
            setTimeout(() => el.classList.remove('is-enter'), 450);
        }

        const count = active.messages.length;
        this.applyGrouping([count - 2, count - 1]);

        if (message.is_mine || wasNearBottom) {
            this.scrollToBottom(true);
        } else {
            active.unseenBelow++;
            this.updateScrollButton();
        }

        return true;
    }

    /** Merge new data into an existing message and re-render its bubble. */
    updateMessage(message, { pop = false } = {}) {
        const active = this.active;
        if (!active) return;
        const index = this.indexOfMessage(message.id);
        if (index === -1) return;

        const current = active.messages[index];
        const changed = Object.keys(message).some((key) => JSON.stringify(current[key]) !== JSON.stringify(message[key]));
        if (!changed) return;

        const merged = { ...current, ...message };
        active.messages[index] = merged;
        active.byId.set(String(merged.id), merged);

        const el = this.messageEl(merged.id);
        if (el) {
            el.outerHTML = this.renderBubble(merged);
            if (pop) this.messageEl(merged.id)?.querySelector('.tick')?.classList.add('is-pop');
        }
        this.applyGrouping([index - 1, index, index + 1]);
    }

    /** Swap an optimistic (temporary) message for the stored one. */
    replaceMessage(tempId, message) {
        const active = this.active;
        if (!active || Number(message.conversation_id) !== active.id) return;

        const index = this.indexOfMessage(tempId);
        if (index === -1) {
            this.appendMessage(message, { animate: false });
            return;
        }

        if (active.byId.has(String(message.id))) {
            // Already delivered through another channel: drop the temporary copy.
            this.messageEl(tempId)?.remove();
            active.messages.splice(index, 1);
            active.byId.delete(String(tempId));
            this.applyGrouping([index - 1, index]);
            return;
        }

        active.messages[index] = message;
        active.byId.delete(String(tempId));
        active.byId.set(String(message.id), message);

        const el = this.messageEl(tempId);
        if (el) el.outerHTML = this.renderBubble(message);
        this.applyGrouping([index - 1, index, index + 1]);
    }

    removeMessageFromView(id) {
        const active = this.active;
        if (!active) return;
        const index = this.indexOfMessage(id);
        if (index === -1) return;

        const el = this.messageEl(id);
        const before = el?.previousElementSibling;
        const after = el?.nextElementSibling;
        el?.remove();

        // Remove a date divider that no longer has messages under it.
        if (before?.matches('[data-date-divider]') && (!after || after.matches('[data-date-divider]'))) before.remove();

        active.messages.splice(index, 1);
        active.byId.delete(String(id));
        this.applyGrouping([index - 1, index]);
    }

    /* ================================================================== */
    /* Composer & sending                                                  */
    /* ================================================================== */

    bindComposer() {
        const { composerForm, composerInput, emojiToggle } = this.el;

        composerInput.addEventListener('input', () => {
            this.autosize();
            this.updateSendState();
            this.onComposerInput();
            this.saveDraftSoon();
            document.dispatchEvent(new CustomEvent('chat:composing', { detail: { chat: this } }));
        });

        composerInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && !isTouchDevice()) {
                event.preventDefault();
                composerForm.requestSubmit();
            }
        });

        composerForm.addEventListener('submit', (event) => {
            event.preventDefault();
            this.submitComposer();
        });

        emojiToggle.addEventListener('click', (event) => {
            event.preventDefault();
            this.emojiPicker.toggle();
        });
    }

    /** Remember unsent text of the open chat (not while editing a message). */
    saveDraft() {
        if (!this.active || this.actions?.mode?.type === 'edit') return;
        this.drafts.set(this.active.id, this.el.composerInput.value);
    }

    autosize() {
        const input = this.el.composerInput;
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 152)}px`;
    }

    updateSendState() {
        const hasText = this.el.composerInput.value.trim().length > 0;
        const hasContent = hasText || Boolean(this.attachments?.file);
        const editing = this.actions?.mode?.type === 'edit';

        this.el.composerSend.disabled = !hasContent;
        // Empty composer shows the microphone instead of the send button.
        this.el.composerForm.classList.toggle('is-empty', !hasContent && !editing && Boolean(this.voice?.supported));
        document.dispatchEvent(new CustomEvent('chat:send-state', { detail: { hasText, chat: this } }));
    }

    insertAtCursor(text) {
        const input = this.el.composerInput;
        const start = input.selectionStart ?? input.value.length;
        const end = input.selectionEnd ?? input.value.length;
        input.setRangeText(text, start, end, 'end');
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    /**
     * Hook: later phases (edit mode, attachments) intercept submission.
     * Returns true when the submission was handled elsewhere.
     */
    beforeSubmit() {
        const event = new CustomEvent('chat:before-submit', { cancelable: true, detail: { chat: this } });
        return !document.dispatchEvent(event);
    }

    submitComposer() {
        if (!this.active) return;
        if (this.beforeSubmit()) return;

        const text = this.el.composerInput.value.trim();
        if (!text) return;

        if (text.length > this.config.limits.messageLength) {
            toast.error(`Messages can be at most ${this.config.limits.messageLength} characters.`);
            return;
        }

        this.el.composerInput.value = '';
        this.drafts.clear(this.active.id);
        this.autosize();
        this.updateSendState();
        this.emojiPicker.close();
        this.stopTyping();

        const send = this.el.composerSend;
        send.classList.remove('is-flying');
        void send.offsetWidth;
        send.classList.add('is-flying');

        this.sendText(this.active.id, text);
        if (!isTouchDevice()) this.el.composerInput.focus();
    }

    /** Extra payload (e.g. reply_to_id) contributed by later phases. */
    composePayload(payload) {
        const detail = { payload, chat: this };
        document.dispatchEvent(new CustomEvent('chat:compose-payload', { detail }));
        return detail.payload;
    }

    /** Send one of my stickers (M17). */
    sendSticker(conversationId, sticker) {
        return this.sendSpecial(conversationId, {
            type: 'sticker',
            fields: { sticker_id: sticker.id },
            attachment: { url: sticker.url, name: 'Sticker.webp', mime: 'image/webp', width: 512, height: 512 },
        });
    }

    /** Send a GIF from GIF search; the server downloads it (M17). */
    sendGif(conversationId, gif) {
        return this.sendSpecial(conversationId, {
            type: 'image',
            fields: { gif_id: gif.id },
            attachment: { url: gif.preview_url, name: 'GIF.gif', mime: 'image/gif', animated: true, width: gif.width, height: gif.height },
        });
    }

    /**
     * A message whose content is already on the server or fetched by it
     * (sticker, GIF): optimistic bubble, JSON request, retry on failure.
     */
    async sendSpecial(conversationId, options) {
        const { type, fields, attachment, extra = {}, retryClientId = null } = options;
        const clientId = retryClientId ?? uuid();
        const existing = retryClientId ? this.pending.get(retryClientId) : null;
        const conversation = this.conversations.get(conversationId);
        const payload = existing?.payload ?? this.composePayload({ ...fields, client_id: clientId });
        const replyPreview = existing?.temp?.reply_to ?? payload.reply_preview ?? null;
        delete payload.reply_preview;
        delete payload.link_preview_data;

        const temp = {
            id: `tmp-${clientId}`,
            client_id: clientId,
            conversation_id: conversationId,
            sender_id: this.me.id,
            receiver_id: conversation?.participant?.id ?? null,
            is_mine: true,
            type,
            body: null,
            is_deleted: false,
            is_edited: false,
            reply_to: replyPreview,
            attachment,
            ...extra,
            status: 'pending',
            created_at: existing?.temp?.created_at ?? new Date().toISOString(),
        };

        this.pending.set(clientId, { kind: 'special', conversationId, payload, temp, options: { type, fields, attachment, extra } });
        if (retryClientId) this.updateMessage(temp);
        else this.appendMessage(temp);

        try {
            const message = this.withCachedStatus(this.normalizeMessage(await this.api.sendMessage(conversationId, payload)));
            this.pending.delete(clientId);
            this.replaceMessage(temp.id, message);
            this.onOwnMessageStored(message);
            return message;
        } catch (error) {
            this.updateMessage({ ...temp, status: 'failed' });
            const fallback = { sticker: 'The sticker was not sent.', location: 'The location was not sent.' }[type] ?? 'The GIF was not sent.';
            toast.error(errorMessage(error, fallback));
            if ([403, 422].includes(error?.response?.status)) {
                this.pending.delete(clientId);
                if (error.response.status === 403) this.refreshConversation(conversationId);
            }
            return null;
        }
    }

    async sendText(conversationId, text, retryClientId = null) {
        const clientId = retryClientId ?? uuid();
        const conversation = this.conversations.get(conversationId);
        const existing = retryClientId ? this.pending.get(retryClientId) : null;
        const payload = existing?.payload ?? this.composePayload({ message: text, client_id: clientId });

        const temp = {
            id: `tmp-${clientId}`,
            client_id: clientId,
            conversation_id: conversationId,
            sender_id: this.me.id,
            receiver_id: conversation?.participant?.id ?? null,
            is_mine: true,
            type: 'text',
            body: text,
            is_deleted: false,
            is_edited: false,
            reply_to: existing?.temp?.reply_to ?? payload.reply_preview ?? null,
            link_preview: existing?.temp?.link_preview ?? payload.link_preview_data ?? null,
            mentions: payload.mentions ?? existing?.temp?.mentions,
            status: 'pending',
            created_at: existing?.temp?.created_at ?? new Date().toISOString(),
        };

        delete payload.reply_preview;
        delete payload.link_preview_data;
        this.pending.set(clientId, { conversationId, text, payload, temp });

        if (retryClientId) {
            this.updateMessage(temp);
        } else {
            this.appendMessage(temp);
        }

        try {
            const message = this.withCachedStatus(this.normalizeMessage(await this.api.sendMessage(conversationId, payload)));
            this.pending.delete(clientId);
            this.replaceMessage(temp.id, message);
            this.onOwnMessageStored(message);
        } catch (error) {
            const messageText = errorMessage(error, 'Message not sent.');
            this.updateMessage({ ...temp, status: 'failed' });
            toast.error(messageText);

            if (error?.response?.status === 403) {
                this.pending.delete(clientId);
                this.refreshConversation(conversationId);
            }
        }
    }

    onOwnMessageStored(message) {
        this.touchConversation(message);
    }

    /**
     * Upload an image, video, document or voice note with an optimistic bubble and progress.
     * Bubbles appear and uploads run in the order files were picked (albums stay in order).
     */
    async sendFile(conversationId, options) {
        const prepared = this.fileChain.then(() => this.prepareFile(conversationId, options));
        this.fileChain = prepared.catch(() => {});
        const { temp, form, clientId, type, localUrl, localThumbnail } = await prepared;

        const request = this.uploadChain.then(() =>
            this.api.sendMessage(conversationId, form, {
                onUploadProgress: (event) => this.setUploadProgress(temp.id, event.total ? event.loaded / event.total : 0),
            }),
        );
        this.uploadChain = request.catch(() => {});

        try {
            const message = this.withCachedStatus(this.normalizeMessage(await request));

            // Keep showing the local preview until the stored image / poster is cached (no flicker).
            const stored = type === 'image' ? message.attachment?.thumbnail_url || message.attachment?.url : type === 'video' ? message.attachment?.thumbnail_url : null;
            if (stored) {
                await Promise.race([
                    new Promise((resolve) => {
                        const img = new Image();
                        img.onload = img.onerror = resolve;
                        img.src = stored;
                    }),
                    new Promise((resolve) => setTimeout(resolve, 4000)),
                ]);
            }
            // A video may still be playing from the local copy: keep it longer.
            if (localUrl) setTimeout(() => URL.revokeObjectURL(localUrl), type === 'video' ? 600_000 : 10_000);
            if (localThumbnail) setTimeout(() => URL.revokeObjectURL(localThumbnail), 10_000);

            this.pending.delete(clientId);
            this.replaceMessage(temp.id, message);
            this.onOwnMessageStored(message);
        } catch (error) {
            this.updateMessage({ ...temp, status: 'failed', uploading: false });
            toast.error(errorMessage(error, 'The file could not be sent.'));

            if ([403, 413, 422].includes(error?.response?.status)) {
                this.pending.delete(clientId);
                if (error.response.status === 403) this.refreshConversation(conversationId);
            }
        }
    }

    /** Optimistic bubble and form data for a file upload. */
    async prepareFile(conversationId, options) {
        const { file, type, caption = '', duration = null, fileName = file.name, retryClientId = null, thumbnail = null, width = 0, height = 0, albumId = null, quality = null, viewOnce = false } = options;
        const clientId = retryClientId ?? uuid();
        const existing = retryClientId ? this.pending.get(retryClientId) : null;
        const conversation = this.conversations.get(conversationId);

        const payload = existing?.payload ?? this.composePayload({ message: caption || null, client_id: clientId });
        const replyPreview = existing?.temp?.reply_to ?? payload.reply_preview ?? null;
        delete payload.reply_preview;
        delete payload.link_preview_data;

        const localUrl = existing?.temp?.attachment?.local_url ?? (type === 'document' ? null : URL.createObjectURL(file));
        const localThumbnail = existing?.temp?.attachment?.local_thumbnail_url ?? (thumbnail ? URL.createObjectURL(thumbnail) : null);
        const dimensions = width && height ? { width, height } : type === 'image' ? await imageSize(localUrl) : {};

        const temp = {
            id: `tmp-${clientId}`,
            client_id: clientId,
            conversation_id: conversationId,
            sender_id: this.me.id,
            receiver_id: conversation?.participant?.id ?? null,
            is_mine: true,
            type,
            body: type === 'voice' ? null : caption || null,
            is_deleted: false,
            is_edited: false,
            reply_to: replyPreview,
            album_id: albumId,
            view_once: viewOnce ? { opened_at: null, available: true } : undefined,
            attachment: { local_url: localUrl, local_thumbnail_url: localThumbnail, name: fileName, size: file.size, mime: file.type, duration, hd: quality === 'hd', view_once: viewOnce || undefined, ...dimensions },
            status: 'pending',
            uploading: true,
            progress: 0,
            created_at: existing?.temp?.created_at ?? new Date().toISOString(),
        };

        this.pending.set(clientId, { kind: 'file', conversationId, payload, temp, options: { file, type, caption, duration, fileName, thumbnail, width, height, albumId, quality, viewOnce } });

        if (retryClientId) {
            this.updateMessage(temp);
        } else {
            this.appendMessage(temp);
        }

        const form = new FormData();
        form.append(type === 'voice' ? 'voice' : 'attachment', file, fileName);
        if (payload.message) form.append('message', payload.message);
        if (payload.reply_to_id) form.append('reply_to_id', payload.reply_to_id);
        (payload.mentions ?? []).forEach((mention, index) => {
            form.append(`mentions[${index}][id]`, String(mention.id));
            form.append(`mentions[${index}][name]`, mention.name);
        });
        if (duration !== null) form.append('duration', String(Math.round(duration * 10) / 10));
        if (thumbnail) form.append('thumbnail', thumbnail, 'poster.jpg');
        if (albumId) form.append('album_id', albumId);
        if (quality) form.append('quality', quality);
        if (viewOnce) form.append('view_once', '1');
        form.append('client_id', clientId);

        return { temp, form, clientId, type, localUrl, localThumbnail };
    }

    setUploadProgress(id, ratio) {
        const bar = this.messageEl(id)?.querySelector('[data-upload-progress]');
        if (bar) bar.style.width = `${Math.round(ratio * 100)}%`;
        const message = this.active?.byId.get(String(id));
        if (message) message.progress = ratio;
    }

    /** A message was deleted "for me" (here or on another device). */
    onMessageHidden({ id, conversation_id: conversationId }) {
        this.removeMessageFromView(id);
        this.pins?.forget(Number(id));

        const conversation = this.conversations.get(Number(conversationId));
        if (conversation?.last_message?.id === Number(id)) {
            this.refreshConversation(Number(conversationId));
        }
    }

    /**
     * Scroll to a message (the original of a reply, a search result…), loading history if needed.
     *
     * @returns {Promise<HTMLElement|null>}
     */
    async jumpToMessage(id, { deep = false } = {}) {
        const active = this.active;
        if (!active) return null;

        // Deep jumps (search, pinned and starred messages) load bigger pages for longer.
        const maxPages = deep ? 60 : 10;
        const pageSize = deep ? 100 : null;
        for (let attempts = 0; !this.messageEl(id) && active.hasMore && attempts < maxPages; attempts++) {
            while (active.loadingOlder) await new Promise((resolve) => setTimeout(resolve, 50));
            await this.loadOlder(pageSize);
            if (this.active !== active) return null;
        }

        const el = this.messageEl(id);
        if (!el) {
            toast.info('That message is no longer available.');
            return null;
        }

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.remove('is-highlighted');
        void el.offsetWidth;
        el.classList.add('is-highlighted');
        setTimeout(() => el.classList.remove('is-highlighted'), 1700);
        return el;
    }

    /**
     * Update the sidebar entry for a new/changed message and move it to the top.
     */
    touchConversation(message, { incrementUnread = false } = {}) {
        const conversation = this.conversations.get(Number(message.conversation_id));

        if (!conversation) {
            this.refreshConversation(Number(message.conversation_id));
            return;
        }

        const isNewest = !conversation.last_message || message.id >= conversation.last_message.id;
        // A deleted chat comes back with a new message (C5).
        if (conversation.settings?.hidden) conversation.settings = { ...conversation.settings, hidden: false };
        if (isNewest) {
            conversation.last_message = {
                id: message.id,
                sender_id: message.sender_id,
                sender_name: message.sender_name ?? null,
                system: message.system ?? null,
                is_mine: message.sender_id === this.me.id,
                type: message.type,
                preview: previewOf(message),
                is_deleted: message.is_deleted,
                status: message.status,
                created_at: message.created_at,
            };
        }

        if (incrementUnread) {
            conversation.unread_count = (conversation.unread_count || 0) + 1;
            if ((message.mentions ?? []).some((mention) => Number(mention.id) === Number(this.me.id))) {
                conversation.unread_mentions = (conversation.unread_mentions || 0) + 1;
            }
        }

        this.renderConversations();
        if (isNewest) this.bumpConversation(conversation.id);
    }

    /* ================================================================== */
    /* Realtime handlers (WebSocket events and polling sync)               */
    /* ================================================================== */

    normalizeMessage(message) {
        // Group messages carry the writer's name for people not yet known here.
        if (message.sender_name && !this.users.get(Number(message.sender_id))?.name) {
            this.rememberUser({ id: Number(message.sender_id), name: message.sender_name });
        }
        // Channels (G11) don't say who reacted or voted: keep my own choice.
        if (this.conversations.get(Number(message.conversation_id))?.type === 'channel') {
            message = keepMyChoices(message, this.active?.byId.get(String(message.id)), this.me.id);
        }
        return { ...message, is_mine: Number(message.sender_id) === Number(this.me.id) };
    }

    isViewingActive() {
        return Boolean(this.active?.loaded) && document.visibilityState === 'visible';
    }

    /** Newer than the latest message we know for its conversation? */
    isNewerThanLatest(message) {
        const conversation = this.conversations.get(Number(message.conversation_id));
        return !conversation?.last_message || message.id > conversation.last_message.id;
    }

    onIncomingMessage(raw) {
        const message = this.withCachedStatus(this.normalizeMessage(raw));
        const conversationId = Number(message.conversation_id);
        this.disappearing?.onNotice(message);

        if (message.is_mine) {
            // Sent from another tab / device.
            this.appendMessage(message);
            this.touchConversation(message);
            return;
        }

        this.setTyping(conversationId, false);

        const viewing = this.active?.id === conversationId && this.isViewingActive();
        // Answered or declined calls arrive already read; only missed calls count as unread.
        const alreadyRead = message.type === 'call' && Boolean(message.seen_at);
        this.appendMessage(message);
        this.touchConversation(message, { incrementUnread: !viewing && !alreadyRead });

        if (viewing) {
            this.markSeenSoon();
        } else {
            this.queueDelivered(message.id);
        }

        document.dispatchEvent(new CustomEvent('chat:incoming', { detail: { message, viewing, chat: this } }));
    }

    onMessageUpdated(raw) {
        const message = this.normalizeMessage(raw);
        this.updateMessage(message);
        if (message.is_deleted) this.pins?.forget(message.id);

        const conversation = this.conversations.get(Number(message.conversation_id));
        const last = conversation?.last_message;
        if (last?.id === message.id) {
            const next = { ...last, preview: previewOf(message), is_deleted: message.is_deleted, status: message.status };
            if (JSON.stringify(next) !== JSON.stringify(last)) {
                conversation.last_message = next;
                this.renderConversations();
            }
        }
    }

    /** Receipts for messages I sent: ✓✓ delivered / blue ✓✓ seen. */
    onStatusUpdate({ conversation_id: conversationId, ids, status, at }) {
        const idSet = new Set((ids ?? []).map(Number));
        const rank = STATUS_RANK[status] ?? 0;
        const active = this.active?.id === Number(conversationId) ? this.active : null;

        for (const id of idSet) {
            const current = active?.byId.get(String(id));

            if (!current) {
                // Not rendered yet (e.g. HTTP response still in flight): remember it.
                const cached = this.statusCache.get(id);
                if (!cached || (STATUS_RANK[cached.status] ?? 0) < rank) this.statusCache.set(id, { status, at });
                if (this.statusCache.size > 500) this.statusCache.delete(this.statusCache.keys().next().value);
                continue;
            }

            if (!current.is_mine || (STATUS_RANK[current.status] ?? 0) >= rank) continue;

            this.updateMessage(
                {
                    id,
                    status,
                    delivered_at: current.delivered_at ?? at,
                    ...(status === 'seen' ? { seen_at: at } : {}),
                },
                { pop: true },
            );
        }

        const last = this.conversations.get(Number(conversationId))?.last_message;
        if (last && idSet.has(last.id) && (STATUS_RANK[last.status] ?? 0) < rank) {
            last.status = status;
            this.renderConversations();
        }
    }

    withCachedStatus(message) {
        const cached = this.statusCache.get(message.id);
        if (!cached || !message.is_mine) return message;
        this.statusCache.delete(message.id);
        if ((STATUS_RANK[message.status] ?? 0) >= (STATUS_RANK[cached.status] ?? 0)) return message;
        return {
            ...message,
            status: cached.status,
            delivered_at: message.delivered_at ?? cached.at,
            seen_at: cached.status === 'seen' ? cached.at : message.seen_at,
        };
    }

    queueDelivered(id) {
        if (!this.api.has('delivered') || typeof id !== 'number') return;
        this.deliveredQueue.add(id);
        clearTimeout(this.deliveredTimer);
        this.deliveredTimer = setTimeout(() => {
            const ids = [...this.deliveredQueue];
            this.deliveredQueue.clear();
            if (ids.length) this.api.markDelivered(ids).catch(() => {});
        }, 400);
    }

    async markActiveSeen() {
        const active = this.active;
        if (!active || !this.api.has('seen') || !this.isViewingActive()) return;

        const conversation = this.conversations.get(active.id);
        const unseen = active.messages.filter((m) => !m.is_mine && !m.seen_at && typeof m.id === 'number');
        if (!unseen.length && !(conversation?.unread_count > 0)) return;

        const now = new Date().toISOString();
        unseen.forEach((m) => (m.seen_at = now));

        if (conversation) {
            conversation.unread_count = 0;
            conversation.unread_mentions = 0;
            this.renderConversations();
        }

        try {
            await this.api.markSeen(active.id);
            document.dispatchEvent(new CustomEvent('chat:seen', { detail: { conversationId: active.id, chat: this } }));
        } catch {
            /* retried on next focus / message */
        }
    }

    /* ---------- Typing ---------- */

    onTyping({ conversation_id: conversationId, typing, action, user_id: userId }) {
        this.setTyping(Number(conversationId), Boolean(typing), action, userId);
    }

    setTyping(conversationId, typing, action = 'typing', userId = null) {
        if (typing && userId) this.typingUsers.set(conversationId, Number(userId));
        clearTimeout(this.typingTimers.get(conversationId));
        const wasTyping = this.typingConversations.has(conversationId);
        const previousAction = this.typingActions.get(conversationId);
        const nextAction = action === 'recording' ? 'recording' : 'typing';

        if (typing) {
            this.typingConversations.add(conversationId);
            this.typingActions.set(conversationId, nextAction);
            const ttl = (this.config.presence?.typingTtlSeconds ?? 5) + 1;
            this.typingTimers.set(conversationId, setTimeout(() => this.setTyping(conversationId, false), ttl * 1000));
        } else {
            this.typingConversations.delete(conversationId);
            this.typingTimers.delete(conversationId);
            this.typingActions.delete(conversationId);
        }

        if (wasTyping === typing && (!typing || previousAction === nextAction)) return;

        this.renderConversations();
        if (this.active?.id === conversationId) {
            this.updateHeaderStatus();
            this.renderTypingRow();
        }
    }

    renderTypingRow() {
        const show = Boolean(this.active && this.typingConversations.has(this.active.id));
        const nearBottom = this.isNearBottom();
        this.el.typingRow.hidden = !show;
        this.el.typingRow.classList.toggle('is-recording', show && this.typingActions.get(this.active.id) === 'recording');
        if (show && nearBottom) this.scrollToBottom(true);
    }

    onComposerInput() {
        if (!this.active?.loaded || !this.api.has('typing')) return;

        const conversation = this.activeConversation();
        if (conversation?.blocked_by_me || conversation?.blocked_me || conversation?.is_self) return;

        if (!this.el.composerInput.value.trim()) {
            this.stopTyping();
            return;
        }

        const id = this.active.id;
        const now = Date.now();
        if (this.typingConversationId !== id || !this.typingSentAt || now - this.typingSentAt > 3000) {
            if (this.typingConversationId && this.typingConversationId !== id) this.stopTyping();
            this.typingSentAt = now;
            this.typingConversationId = id;
            this.api.typing(id, true).catch(() => {});
        }

        clearTimeout(this.typingStopTimer);
        this.typingStopTimer = setTimeout(() => this.stopTyping(), 3500);
    }

    /** Tell the other person you are recording a voice message (refreshed while recording). */
    startRecordingIndicator() {
        const conversation = this.activeConversation();
        if (!this.active?.loaded || !this.api.has('typing') || conversation?.blocked_by_me || conversation?.blocked_me || conversation?.is_self) return;

        this.stopTyping();
        const id = this.active.id;
        const send = () => this.api.typing(id, true, 'recording').catch(() => {});
        send();
        clearInterval(this.recordingTimer);
        this.recordingTimer = setInterval(send, 3000);
        this.recordingConversationId = id;
    }

    stopRecordingIndicator() {
        if (!this.recordingTimer) return;
        clearInterval(this.recordingTimer);
        this.recordingTimer = null;
        this.api.typing(this.recordingConversationId, false, 'recording').catch(() => {});
        this.recordingConversationId = null;
    }

    stopTyping() {
        clearTimeout(this.typingStopTimer);
        if (this.typingConversationId && this.typingSentAt) {
            this.api.typing(this.typingConversationId, false).catch(() => {});
        }
        this.typingSentAt = null;
        this.typingConversationId = null;
    }

    /* ---------- Presence ---------- */

    onPresenceHere(users) {
        users.forEach((user) => this.rememberUser({ ...user, is_online: true }));
        this.refreshPresenceViews();
    }

    onPresenceJoin(user) {
        this.rememberUser({ ...user, is_online: true });
        this.refreshPresenceViews();
    }

    onPresenceLeave(user) {
        this.rememberUser({ id: user.id, is_online: false, last_seen: new Date().toISOString() });
        this.setTypingForUser(user.id, false);
        this.refreshPresenceViews();
    }

    onPresenceUpdate(user) {
        if (!user?.id) return;
        this.rememberUser(user);
        this.refreshPresenceViews();
    }

    setTypingForUser(userId, typing) {
        for (const conversation of this.conversations.values()) {
            if (conversation.participant?.id === userId) this.setTyping(conversation.id, typing);
        }
    }

    refreshPresenceViews() {
        cancelAnimationFrame(this.presenceFrame);
        this.presenceFrame = requestAnimationFrame(() => {
            this.renderConversations();
            this.updateHeaderStatus();
        });
    }

    /* ---------- Polling sync ---------- */

    applySync(data) {
        let presenceChanged = false;
        for (const user of data.presence ?? []) {
            const known = this.users.get(user.id);
            if (!known || known.is_online !== user.is_online || known.last_seen !== user.last_seen) presenceChanged = true;
            this.rememberUser(user);
        }

        let unknownConversation = false;

        for (const raw of data.messages ?? []) {
            if (raw.hidden) {
                this.removeMessageFromView(raw.id);
                continue;
            }

            const message = this.normalizeMessage(raw);
            if (!this.conversations.has(Number(message.conversation_id))) unknownConversation = true;

            if (this.active?.byId.has(String(message.id))) {
                this.onMessageUpdated(raw);
                continue;
            }

            if (this.isNewerThanLatest(message)) {
                // Skip my own messages that this tab is still sending.
                if (message.is_mine && [...this.pending.values()].some((p) => p.text === message.body)) continue;
                this.onIncomingMessage(raw);
            } else {
                this.onMessageUpdated(raw);
            }
        }

        if (data.typing) this.setTyping(Number(data.typing.conversation_id), Boolean(data.typing.typing), data.typing.action, data.typing.user_id);
        if (Array.isArray(data.calls)) this.calls?.syncCalls(data.calls);
        if (presenceChanged) this.refreshPresenceViews();
        if (unknownConversation || data.truncated) this.loadConversations();
    }

    async refreshConversation(id) {
        try {
            const conversation = await this.api.conversation(id);
            const merged = this.upsertConversation(conversation);
            if (merged.settings?.hidden && this.active?.id === id) {
                this.closeConversation();
                return merged;
            }
            if (this.active?.id === id) {
                this.renderHeader(merged);
                this.updateComposerState(merged);
            }
            return merged;
        } catch (error) {
            // Locked on another device (C9): reload the list without its details.
            if (error?.response?.status === 423) {
                if (this.active?.id === id) this.closeConversation();
                this.loadConversations();
            }
            return null;
        }
    }
}
