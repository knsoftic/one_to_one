import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { formatListTime } from './format';
import * as T from './templates';

/**
 * Star / unstar messages and the "Starred messages" list in the sidebar.
 */
export class StarredMessages {
    constructor(chat) {
        this.chat = chat;
        this.items = [];
        this.hasMore = false;
        this.loading = false;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="starred"]'),
            scroll: q('[data-starred-scroll]'),
            list: q('[data-starred-list]'),
            more: q('[data-starred-more]'),
        };

        if (!this.el.view || !chat.api.has('starred')) return;
        this.bind();
    }

    get isOpen() {
        return this.el.sidebar?.dataset.mode === 'starred';
    }

    bind() {
        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'open-starred') this.open();
            if (event.detail.action === 'close-starred') this.close();
        });

        this.el.list.addEventListener('click', (event) => {
            const item = event.target.closest('[data-starred-message]');
            if (item) this.openMessage(Number(item.dataset.starredConversation), Number(item.dataset.starredMessage));
        });

        this.el.scroll.addEventListener(
            'scroll',
            () => {
                const { scrollTop, scrollHeight, clientHeight } = this.el.scroll;
                if (this.hasMore && scrollHeight - scrollTop - clientHeight < 200) this.load();
            },
            { passive: true },
        );

        document.addEventListener('chat:sidebar-mode', (event) => {
            if (event.detail.mode !== 'starred' && !this.el.view.hidden) this.close({ silent: true });
        });

        document.addEventListener('app:back', (event) => {
            if (this.isOpen && !this.chat.active) {
                event.preventDefault();
                this.close();
            }
        });
    }

    /* ------------------------------------------------------------------ */

    async toggle(message) {
        const next = !message.is_starred;
        this.chat.updateMessage({ id: message.id, is_starred: next });

        try {
            await (next ? this.chat.api.star(message.id) : this.chat.api.unstar(message.id));
            toast.success(next ? 'Message starred.' : 'Message unstarred.', { timeout: 1800 });
            if (!next) this.removeFromList(message.id);
        } catch (error) {
            this.chat.updateMessage({ id: message.id, is_starred: !next });
            toast.error(errorMessage(error, 'Could not update the star.'));
        }
    }

    open() {
        if (this.chat.contactsPanel?.isOpen) this.chat.contactsPanel.close();
        if (this.chat.callLog?.isOpen) this.chat.callLog.close({ silent: true });
        this.el.sidebar.dataset.mode = 'starred';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'starred' } }));

        this.items = [];
        this.hasMore = false;
        this.el.list.innerHTML = '<div class="flex justify-center p-6 text-primary"><span class="spinner"></span></div>';
        this.load(true);
    }

    close({ silent = false } = {}) {
        this.el.view.hidden = true;
        // Silent: another sidebar view (e.g. contacts) is taking over and manages the chats view itself.
        if (silent) return;
        this.el.chatsView.hidden = false;
        if (this.el.sidebar.dataset.mode === 'starred') this.el.sidebar.dataset.mode = 'chats';
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    async load(reset = false) {
        if (this.loading) return;
        this.loading = true;
        this.el.more.hidden = reset;

        try {
            const before = reset ? null : this.items.at(-1)?.star_id;
            const data = await this.chat.api.starred(before);
            this.items = reset ? data.data : [...this.items, ...data.data];
            this.hasMore = data.has_more;
            this.render();
        } catch (error) {
            this.el.list.innerHTML = T.emptyState({ iconName: 'circle-alert', title: "Couldn't load starred messages", text: errorMessage(error) });
        } finally {
            this.loading = false;
            this.el.more.hidden = true;
        }
    }

    render() {
        if (!this.items.length) {
            this.el.list.innerHTML = T.emptyState({
                iconName: 'star',
                title: 'No starred messages',
                text: 'Long-press or open the menu of any message and choose Star to find it here later.',
            });
            return;
        }

        const meId = Number(this.chat.me.id);
        this.el.list.innerHTML = this.items
            .map(({ message, peer }) => {
                const person = this.chat.decorate({ ...peer, name: peer.saved_name || peer.name });
                const from = Number(message.sender_id) === meId ? `You ▸ ${person.name}` : `${person.name} ▸ You`;
                const normalized = this.chat.normalizeMessage(message);

                return html`
                    <button type="button" class="starred-item" data-starred-message="${message.id}" data-starred-conversation="${message.conversation_id}">
                        ${raw(T.avatar(Number(message.sender_id) === meId ? this.chat.me : person, 'sm'))}
                        <span class="starred-body">
                            <span class="starred-row">
                                <span class="starred-from">${from}</span>
                                <time class="starred-time" datetime="${message.created_at}">${formatListTime(message.created_at)}</time>
                            </span>
                            <span class="starred-text">${raw(icon('star'))}${T.previewOf(normalized) || 'Message'}</span>
                        </span>
                    </button>
                `;
            })
            .join('');
    }

    removeFromList(messageId) {
        const before = this.items.length;
        this.items = this.items.filter((item) => item.message.id !== messageId);
        if (this.items.length !== before && this.isOpen) this.render();
    }

    async openMessage(conversationId, messageId) {
        this.close();
        await this.chat.openConversation(conversationId);
        // Wait for the chat to finish loading before jumping.
        for (let i = 0; i < 50 && !this.chat.active?.loaded; i++) await new Promise((resolve) => setTimeout(resolve, 100));
        if (this.chat.active?.id === conversationId) this.chat.jumpToMessage(messageId, { deep: true });
    }
}
