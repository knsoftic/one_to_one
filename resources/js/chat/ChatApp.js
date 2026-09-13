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
import { dayKey, formatLastSeen } from './format';
import { openLightbox } from './lightbox';
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
        this.onlineUserIds = [];
        this.pending = new Map();
        this.typingConversations = new Set();
        /** @type {Map<number, string>} names saved in the user's phone book */
        this.savedNames = new Map();
        this.typingTimers = new Map();
        this.deliveredQueue = new Set();
        this.statusCache = new Map();
        this.typingSentAt = null;
        this.typingConversationId = null;
        this.markSeenSoon = debounce(() => this.markActiveSeen(), 250);

        this.filter = 'all';
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
            onlineStrip: q('[data-online-strip]'),
            onlineList: q('[data-online-list]'),
            onlineCount: q('[data-online-count]'),
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
        this.emojiPicker = new EmojiPicker(this.el.composer, (emoji) => this.insertAtCursor(emoji));

        T.setTemplateContext({
            meId: this.me.id,
            nameOf: (userId) => this.displayName(userId, this.users.get(Number(userId))?.name ?? ''),
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
        this.contactsPanel = new ContactsPanel(this);
        this.calls = new CallManager(this);
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
        const { searchInput, searchClear, filters, conversationList, sidebarScroll, onlineList } = this.el;
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
            this.filter = button.dataset.filter;
            filters.querySelectorAll('[data-filter]').forEach((b) => {
                const active = b === button;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            this.renderConversations();
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
        onlineList.addEventListener('click', onPick);
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
        return this.savedNames.get(Number(userId)) ?? fallback;
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
        this.renderOnlineUsers();
        const conversation = this.activeConversation();
        if (conversation) this.renderHeader(conversation);
    }

    bindMobileNav() {
        const tabs = document.querySelectorAll('[data-mobile-tab]');

        tabs.forEach((tab) =>
            tab.addEventListener('click', () => {
                if (tab.dataset.mobileTab === 'contacts') {
                    this.contactsPanel.open();
                } else if (this.contactsPanel.isOpen) {
                    this.contactsPanel.close();
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
        const participant = conversation?.participant;
        if (!participant) return null;
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

    sortedConversations() {
        const time = (c) => new Date(c.last_message.created_at).getTime() || 0;

        return [...this.conversations.values()]
            .filter((c) => c.last_message)
            .sort((a, b) => time(b) - time(a) || (b.last_message.id ?? 0) - (a.last_message.id ?? 0));
    }

    renderConversations() {
        if (!this.conversationsLoaded) return;

        const all = this.sortedConversations();
        const visible = this.filter === 'unread' ? all.filter((c) => c.unread_count > 0) : all;
        const list = this.el.conversationList;

        if (!all.length) {
            list.innerHTML = T.emptyState({
                iconName: 'message-square-plus',
                title: 'No conversations yet',
                text: 'Search for someone by name, username, email or mobile number to start chatting.',
            });
        } else if (!visible.length) {
            list.innerHTML = T.emptyState({ iconName: 'check-check', title: 'All caught up', text: 'You have no unread messages.' });
        } else {
            list.innerHTML = visible
                .map((conversation) =>
                    T.conversationItem(
                        { ...conversation, participant: this.participantOf(conversation) },
                        { active: conversation.id === this.active?.id, typing: this.typingConversations.has(conversation.id) },
                    ),
                )
                .join('');
        }

        this.updateUnreadTotals();
    }

    updateUnreadTotals() {
        const total = [...this.conversations.values()].reduce((sum, c) => sum + (c.unread_count || 0), 0);
        const badge = this.el.totalUnread;
        badge.hidden = total === 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        document.title = total > 0 ? `(${total}) ${this.baseTitle}` : this.baseTitle;

        const mobileBadge = document.querySelector('[data-mobile-unread]');
        if (mobileBadge) {
            mobileBadge.hidden = total === 0;
            mobileBadge.textContent = total > 99 ? '99+' : String(total);
        }
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
        const { searchResults, conversationList, filters, onlineStrip } = this.el;
        searchResults.hidden = false;
        conversationList.hidden = true;
        filters.hidden = true;
        onlineStrip.dataset.searching = '1';
        onlineStrip.hidden = true;
        if (!searchResults.innerHTML.trim()) {
            searchResults.innerHTML = '<div class="flex justify-center p-6 text-primary"><span class="spinner"></span></div>';
        }
    }

    clearSearch() {
        const { searchInput, searchClear, searchResults, conversationList, filters, onlineStrip } = this.el;
        this.searchAbort?.abort();
        searchInput.value = '';
        searchClear.hidden = true;
        searchResults.hidden = true;
        searchResults.innerHTML = '';
        conversationList.hidden = false;
        filters.hidden = false;
        delete onlineStrip.dataset.searching;
        this.renderOnlineUsers();
    }

    async search(term) {
        this.searchAbort?.abort();
        const controller = new AbortController();
        this.searchAbort = controller;

        const needle = term.toLowerCase().replace(/^@/, '');
        const localMatches = this.sortedConversations().filter((c) => {
            const p = c.participant ?? {};
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

    async loadOnlineUsers() {
        try {
            const users = await this.api.onlineUsers();
            users.forEach((user) => this.rememberUser(user));
            this.onlineUserIds = users.map((user) => user.id);
            this.renderOnlineUsers();
        } catch {
            /* non-critical */
        }
    }

    renderOnlineUsers() {
        const { onlineStrip, onlineList, onlineCount } = this.el;
        const hidden = this.blockedMeIds();
        const users = this.onlineUserIds
            .map((id) => this.users.get(id))
            .filter((u) => u && u.is_online && u.id !== this.me.id && !hidden.has(u.id))
            .map((u) => this.decorate(u));

        onlineStrip.hidden = users.length === 0 || onlineStrip.dataset.searching === '1';
        onlineCount.textContent = users.length ? String(users.length) : '';
        onlineList.innerHTML = users.map((user) => T.onlineUser(user)).join('');
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
        this.el.composerInput.value = '';
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
            if (status === 404 || status === 403) {
                toast.error('This conversation is not available.');
                this.closeConversation({ navigation: 'replace' });
                return;
            }
            this.el.messageList.innerHTML = T.emptyState({ iconName: 'wifi-off', title: "Couldn't load messages", text: errorMessage(error) });
        }
    }

    closeConversation({ navigation = 'push' } = {}) {
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

        status.classList.toggle('is-typing', typing);
        status.classList.toggle('is-online', !typing && !hidePresence && Boolean(user?.is_online));
        avatarEl?.classList.toggle('is-online', !hidePresence && Boolean(user?.is_online));

        if (typing) {
            status.textContent = 'typing…';
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
        const notice = conversation?.blocked_by_me
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
            const retry = event.target.closest('[data-retry]');
            if (retry) {
                const pending = this.pending.get(retry.dataset.retry);
                if (!pending) return;
                if (pending.kind === 'file') {
                    this.sendFile(pending.conversationId, { ...pending.options, retryClientId: retry.dataset.retry });
                } else {
                    this.sendText(pending.conversationId, pending.text, retry.dataset.retry);
                }
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
    }

    renderMessages(messages) {
        const active = this.active;
        active.messages = [];
        active.byId.clear();

        if (!messages.length) {
            const user = this.participantOf(this.activeConversation());
            this.el.messageList.innerHTML = T.historyStart() + (user ? T.conversationIntro(user) : '');
            this.el.olderSentinel.hidden = true;
            return;
        }

        const parts = active.hasMore ? [] : [T.historyStart()];
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
        return T.messageBubble(message);
    }

    async loadOlder() {
        const active = this.active;
        if (!active || !active.loaded || !active.hasMore || active.loadingOlder) return;

        const oldest = active.messages.find((m) => typeof m.id === 'number');
        if (!oldest) return;

        active.loadingOlder = true;
        const token = this.openToken;

        try {
            const page = await this.api.messages(active.id, oldest.id);
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

        const parts = hasMore ? [] : [T.historyStart()];
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
            status: 'pending',
            created_at: existing?.temp?.created_at ?? new Date().toISOString(),
        };

        delete payload.reply_preview;
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
     * Upload an image, document or voice note with an optimistic bubble and progress.
     */
    async sendFile(conversationId, options) {
        const { file, type, caption = '', duration = null, fileName = file.name, retryClientId = null } = options;
        const clientId = retryClientId ?? uuid();
        const existing = retryClientId ? this.pending.get(retryClientId) : null;
        const conversation = this.conversations.get(conversationId);

        const payload = existing?.payload ?? this.composePayload({ message: caption || null, client_id: clientId });
        const replyPreview = existing?.temp?.reply_to ?? payload.reply_preview ?? null;
        delete payload.reply_preview;

        const localUrl = existing?.temp?.attachment?.local_url ?? (type === 'document' ? null : URL.createObjectURL(file));
        const dimensions = type === 'image' ? await imageSize(localUrl) : {};

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
            attachment: { local_url: localUrl, name: fileName, size: file.size, mime: file.type, duration, ...dimensions },
            status: 'pending',
            uploading: true,
            progress: 0,
            created_at: existing?.temp?.created_at ?? new Date().toISOString(),
        };

        this.pending.set(clientId, { kind: 'file', conversationId, payload, temp, options: { file, type, caption, duration, fileName } });

        if (retryClientId) {
            this.updateMessage(temp);
        } else {
            this.appendMessage(temp);
        }

        const form = new FormData();
        form.append(type === 'voice' ? 'voice' : 'attachment', file, fileName);
        if (payload.message) form.append('message', payload.message);
        if (payload.reply_to_id) form.append('reply_to_id', payload.reply_to_id);
        if (duration !== null) form.append('duration', String(duration));
        form.append('client_id', clientId);

        try {
            const response = await this.api.sendMessage(conversationId, form, {
                onUploadProgress: (event) => this.setUploadProgress(temp.id, event.total ? event.loaded / event.total : 0),
            });
            const message = this.withCachedStatus(this.normalizeMessage(response));

            // Keep showing the local preview until the stored image is cached (no flicker).
            if (type === 'image' && message.attachment) {
                await Promise.race([
                    new Promise((resolve) => {
                        const img = new Image();
                        img.onload = img.onerror = resolve;
                        img.src = message.attachment.thumbnail_url || message.attachment.url;
                    }),
                    new Promise((resolve) => setTimeout(resolve, 4000)),
                ]);
            }
            if (localUrl) setTimeout(() => URL.revokeObjectURL(localUrl), 10_000);

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

    setUploadProgress(id, ratio) {
        const bar = this.messageEl(id)?.querySelector('[data-upload-progress]');
        if (bar) bar.style.width = `${Math.round(ratio * 100)}%`;
        const message = this.active?.byId.get(String(id));
        if (message) message.progress = ratio;
    }

    /** A message was deleted "for me" (here or on another device). */
    onMessageHidden({ id, conversation_id: conversationId }) {
        this.removeMessageFromView(id);

        const conversation = this.conversations.get(Number(conversationId));
        if (conversation?.last_message?.id === Number(id)) {
            this.refreshConversation(Number(conversationId));
        }
    }

    /** Scroll to a message (e.g. the original of a reply), loading history if needed. */
    async jumpToMessage(id) {
        const active = this.active;
        if (!active) return;

        for (let attempts = 0; !this.messageEl(id) && active.hasMore && attempts < 10; attempts++) {
            await this.loadOlder();
            if (this.active !== active) return;
        }

        const el = this.messageEl(id);
        if (!el) {
            toast.info('The original message is no longer available.');
            return;
        }

        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.remove('is-highlighted');
        void el.offsetWidth;
        el.classList.add('is-highlighted');
        setTimeout(() => el.classList.remove('is-highlighted'), 1700);
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
        if (isNewest) {
            conversation.last_message = {
                id: message.id,
                sender_id: message.sender_id,
                is_mine: message.sender_id === this.me.id,
                type: message.type,
                preview: previewOf(message),
                is_deleted: message.is_deleted,
                status: message.status,
                created_at: message.created_at,
            };
        }

        if (incrementUnread) conversation.unread_count = (conversation.unread_count || 0) + 1;

        this.renderConversations();
        if (isNewest) this.bumpConversation(conversation.id);
    }

    /* ================================================================== */
    /* Realtime handlers (WebSocket events and polling sync)               */
    /* ================================================================== */

    normalizeMessage(message) {
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

    onTyping({ conversation_id: conversationId, typing }) {
        this.setTyping(Number(conversationId), Boolean(typing));
    }

    setTyping(conversationId, typing) {
        clearTimeout(this.typingTimers.get(conversationId));
        const wasTyping = this.typingConversations.has(conversationId);

        if (typing) {
            this.typingConversations.add(conversationId);
            const ttl = (this.config.presence?.typingTtlSeconds ?? 5) + 1;
            this.typingTimers.set(conversationId, setTimeout(() => this.setTyping(conversationId, false), ttl * 1000));
        } else {
            this.typingConversations.delete(conversationId);
            this.typingTimers.delete(conversationId);
        }

        if (wasTyping === typing) return;

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
        if (show && nearBottom) this.scrollToBottom(true);
    }

    onComposerInput() {
        if (!this.active?.loaded || !this.api.has('typing')) return;

        const conversation = this.activeConversation();
        if (conversation?.blocked_by_me || conversation?.blocked_me) return;

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
        this.onlineUserIds = [...new Set([...users.map((u) => u.id), ...this.onlineUserIds])];
        this.refreshPresenceViews();
    }

    onPresenceJoin(user) {
        this.rememberUser({ ...user, is_online: true });
        this.onlineUserIds = [user.id, ...this.onlineUserIds.filter((id) => id !== user.id)];
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
        if (user.is_online && !this.onlineUserIds.includes(user.id)) this.onlineUserIds.unshift(user.id);
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
            this.renderOnlineUsers();
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
            if (user.is_online && !this.onlineUserIds.includes(user.id)) this.onlineUserIds.push(user.id);
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

        if (data.typing) this.setTyping(Number(data.typing.conversation_id), Boolean(data.typing.typing));
        if (Array.isArray(data.calls)) this.calls?.syncCalls(data.calls);
        if (presenceChanged) this.refreshPresenceViews();
        if (unknownConversation || data.truncated) this.loadConversations();
    }

    async refreshConversation(id) {
        try {
            const conversation = await this.api.conversation(id);
            const merged = this.upsertConversation(conversation);
            if (this.active?.id === id) {
                this.renderHeader(merged);
                this.updateComposerState(merged);
            }
            return merged;
        } catch {
            return null;
        }
    }
}
