import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import * as T from './templates';

export const MAX_CHATS = 5;

/**
 * "Forward to…" dialog: pick up to five recent chats or saved contacts.
 */
export class ForwardDialog {
    constructor(chat) {
        this.chat = chat;
        this.root = null;
    }

    /** Title, what is being sent and the button (the share dialog changes them). */
    labels() {
        return { title: 'Forward to…', preview: T.previewOf(this.message) || 'Message', icon: 'forward', button: 'Forward', busy: 'Forwarding…' };
    }

    open(message) {
        this.close();

        this.message = message;
        this.selected = new Map();
        this.targets = this.buildTargets();
        const labels = this.labels();

        const root = document.createElement('div');
        root.className = 'modal forward-modal';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', 'forward-title');
        root.innerHTML = html`
            <div class="modal-backdrop" data-forward-close></div>
            <div class="modal-panel forward-panel">
                <div class="forward-head">
                    <h2 class="modal-title" id="forward-title">${labels.title}</h2>
                    <button type="button" class="btn-icon" data-forward-close aria-label="Close">${raw(icon('x'))}</button>
                </div>
                <p class="forward-preview">${raw(icon(labels.icon))}<span>${labels.preview}</span></p>
                <input type="search" class="form-control forward-search" data-forward-search placeholder="Search chats and contacts" autocomplete="off" aria-label="Search chats and contacts">
                <div class="forward-list" data-forward-list></div>
                <div class="forward-footer">
                    <span class="forward-count" data-forward-count aria-live="polite">Choose up to ${MAX_CHATS} chats</span>
                    <button type="button" class="btn btn-primary" data-forward-send disabled>${raw(icon('send-horizontal'))} ${labels.button}</button>
                </div>
            </div>
        `;

        this.root = root;
        this.el = {
            search: root.querySelector('[data-forward-search]'),
            list: root.querySelector('[data-forward-list]'),
            count: root.querySelector('[data-forward-count]'),
            send: root.querySelector('[data-forward-send]'),
        };

        root.addEventListener('click', (event) => {
            if (event.target.closest('[data-forward-close]')) return this.close();
            const item = event.target.closest('[data-forward-key]');
            if (item) this.toggle(item.dataset.forwardKey);
            if (event.target.closest('[data-forward-send]')) this.submit();
        });
        this.el.search.addEventListener('input', () => this.renderList());
        this.onKey = (event) => {
            if (event.key === 'Escape') this.close();
        };
        document.addEventListener('keydown', this.onKey);

        document.body.appendChild(root);
        this.renderList();
        if (!window.matchMedia('(pointer: coarse)').matches) this.el.search.focus();
    }

    close() {
        if (!this.root) return;
        document.removeEventListener('keydown', this.onKey);
        this.root.remove();
        this.root = null;
    }

    /** Recent chats first, then saved contacts you have no chat with yet. */
    buildTargets() {
        const chat = this.chat;
        const conversations = chat
            .sortedConversations()
            .filter((conversation) => !conversation.blocked_by_me && !conversation.blocked_me)
            .map((conversation) => ({
                key: `c${conversation.id}`,
                conversationId: conversation.id,
                user: chat.participantOf(conversation) ?? {},
                section: 'Recent chats',
            }));

        const withChat = new Set(conversations.map((target) => Number(target.user.id)));
        const contacts = (chat.contactsPanel?.contacts ?? [])
            .filter((contact) => contact.user && !withChat.has(Number(contact.user.id)))
            .map((contact) => ({
                key: `u${contact.user.id}`,
                userId: contact.user.id,
                user: { ...contact.user, name: contact.name },
                section: 'Contacts',
            }));

        return [...conversations, ...contacts];
    }

    renderList() {
        const term = this.el.search.value.trim().toLowerCase();
        const visible = this.targets.filter((target) => {
            if (!term) return true;
            const { name = '', username = '' } = target.user;
            return name.toLowerCase().includes(term) || username.toLowerCase().includes(term);
        });

        if (!visible.length) {
            this.el.list.innerHTML = T.emptyState({ iconName: 'search', title: 'No chats found', text: 'Try another name.' });
            return;
        }

        let section = null;
        this.el.list.innerHTML = visible
            .map((target) => {
                const heading = target.section !== section ? T.sectionTitle(target.section) : '';
                section = target.section;
                const selected = this.selected.has(target.key);
                return heading + html`
                    <button type="button" class="forward-item${selected ? ' is-selected' : ''}" data-forward-key="${target.key}" aria-pressed="${selected ? 'true' : 'false'}">
                        ${raw(T.avatar(target.user, 'sm'))}
                        <span class="forward-item-name">${target.user.name ?? 'Unknown user'}</span>
                        <span class="forward-check" aria-hidden="true">${raw(icon('check'))}</span>
                    </button>
                `;
            })
            .join('');
    }

    toggle(key) {
        if (this.selected.has(key)) {
            this.selected.delete(key);
        } else {
            if (this.selected.size >= MAX_CHATS) {
                toast.info(`You can forward to at most ${MAX_CHATS} chats at a time.`);
                return;
            }
            this.selected.set(key, this.targets.find((target) => target.key === key));
        }

        const count = this.selected.size;
        this.el.count.textContent = count
            ? [...this.selected.values()].map((target) => target.user.name).join(', ')
            : `Choose up to ${MAX_CHATS} chats`;
        this.el.send.disabled = count === 0;
        this.renderList();
    }

    async submit() {
        const targets = [...this.selected.values()];
        if (!targets.length || this.sending) return;

        const labels = this.labels();
        this.sending = true;
        this.el.send.disabled = true;
        this.el.send.innerHTML = `<span class="spinner"></span> ${labels.busy}`;

        try {
            await this.deliver(await this.conversationIdsFor(targets));
        } catch (error) {
            toast.error(errorMessage(error, labels.button === 'Forward' ? 'Could not forward the message.' : 'Could not send.'));
            if (this.root) {
                this.el.send.disabled = false;
                this.el.send.innerHTML = `${icon('send-horizontal')} ${labels.button}`;
            }
        } finally {
            this.sending = false;
        }
    }

    /** Contacts without a chat yet get one first. */
    async conversationIdsFor(targets) {
        const conversationIds = [];
        for (const target of targets) {
            if (target.conversationId) {
                conversationIds.push(target.conversationId);
            } else {
                const conversation = await this.chat.api.startConversation(target.userId);
                this.chat.upsertConversation(conversation);
                conversationIds.push(conversation.id);
            }
        }
        return conversationIds;
    }

    async deliver(conversationIds) {
        const { data } = await this.chat.api.forwardMessage(this.message.id, conversationIds);

        for (const item of data) {
            const message = this.chat.normalizeMessage(item);
            this.chat.appendMessage(message);
            this.chat.touchConversation(message);
        }

        this.close();
        toast.success(data.length === 1 ? 'Message forwarded.' : `Forwarded to ${data.length} chats.`, { timeout: 2500 });
    }
}
