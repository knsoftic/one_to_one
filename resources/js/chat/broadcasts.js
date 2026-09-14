import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { callablePeople, pickPeople } from './people-picker';
import * as T from './templates';

/**
 * G9 — Broadcast lists: send one message to many people, each in their own chat.
 */

/** Name of a list: its own name, or "3 recipients". */
export function broadcastName(broadcast) {
    if (broadcast?.name) return broadcast.name;
    const count = Number(broadcast?.recipient_count ?? 0);
    return count === 1 ? '1 recipient' : `${count} recipients`;
}

export class Broadcasts {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        this.panelConversationId = null;

        if (!chat.api.has('broadcastsStore')) {
            document.querySelectorAll('[data-action="new-broadcast"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'new-broadcast') this.create();
            if (event.detail.action === 'broadcast-info') this.openInfo(this.chat.active?.id);
        });

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (conversation?.type !== 'broadcast') return;
            items.unshift(html`<button type="button" class="dropdown-item" data-action="broadcast-info" role="menuitem">${raw(icon('megaphone'))} Broadcast list info</button>`);
        });

        chat.el.headerUser.addEventListener('click', () => {
            if (this.chat.activeConversation()?.type === 'broadcast') this.openInfo(this.chat.active.id);
        });
        document.addEventListener('chat:closed', () => this.closeInfo());
    }

    async create() {
        const max = Number(this.chat.config.groups?.maxBroadcastRecipients ?? 256);
        const choice = await pickPeople(this.chat, { title: 'New broadcast list', max, submitLabel: 'Create' });
        if (!choice) return;
        if (choice.ids.length < 2) {
            toast.info('Choose at least 2 people for a broadcast list.');
            return;
        }

        try {
            const conversation = await this.chat.api.createBroadcast({ user_ids: choice.ids });
            this.chat.upsertConversation(conversation);
            this.chat.openConversation(conversation.id);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't create the broadcast list."));
        }
    }

    async openInfo(conversationId) {
        if (!conversationId) return;
        let conversation = this.chat.conversations.get(Number(conversationId));
        if (!conversation?.broadcast?.recipients) conversation = await this.chat.refreshConversation(Number(conversationId));
        if (!conversation?.broadcast) return;

        this.closeInfo();
        const overlay = document.createElement('div');
        overlay.className = 'group-info';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Broadcast list info');
        overlay.innerHTML = '<div class="group-info-backdrop" data-info-close></div><aside class="group-info-panel" data-info-body></aside>';
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.panelConversationId = Number(conversationId);
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onClick(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) this.closeInfo();
        };
        document.addEventListener('keydown', this.onKey);
        this.render(conversation);
        overlay.querySelector('[data-info-close].btn-icon')?.focus();
    }

    closeInfo() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.panelConversationId = null;
        this.previousFocus?.focus?.();
    }

    render(conversation) {
        if (!this.panel) return;
        const broadcast = conversation.broadcast;
        const recipients = broadcast.recipients ?? [];

        this.panel.querySelector('[data-info-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-info-close aria-label="Close broadcast list info">${raw(icon('x'))}</button>
                <span class="group-info-title">Broadcast list info</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero">
                    ${raw(T.avatar(this.chat.participantOf(conversation), 'xl'))}
                    <div class="group-info-name-row">
                        <h2 class="group-info-name">${broadcastName(broadcast)}</h2>
                        <button type="button" class="btn-icon btn-icon-sm" data-broadcast-rename aria-label="Rename broadcast list">${raw(icon('pencil'))}</button>
                    </div>
                    <p class="group-info-count">Broadcast list · ${recipients.length === 1 ? '1 recipient' : `${recipients.length} recipients`}</p>
                </section>
                <section class="group-info-section">
                    <p class="group-info-description">Messages you send here reach each person in their own chat with you. They don't see the other recipients, and their replies come to your one-to-one chat.</p>
                </section>
                <section class="group-info-section">
                    <span class="group-info-label">Recipients</span>
                    <button type="button" class="group-info-action" data-broadcast-edit>${raw(icon('user-plus'))} Edit recipients</button>
                    <div class="group-members">
                        ${raw(recipients.map((user) => html`
                            <div class="group-member">
                                ${raw(T.avatar(this.chat.presenceOf(user), 'md'))}
                                <span class="group-member-body">
                                    <span class="group-member-name">${this.chat.displayName(user.id, user.saved_name || user.name)}</span>
                                    <span class="group-member-meta">@${user.username ?? ''}</span>
                                </span>
                            </div>`).join(''))}
                    </div>
                </section>
                <section class="group-info-section group-info-danger">
                    <button type="button" class="group-info-action is-danger" data-broadcast-delete>${raw(icon('trash-2'))} Delete broadcast list</button>
                </section>
            </div>
        `;
    }

    async onClick(event) {
        const conversation = this.chat.conversations.get(this.panelConversationId);
        if (!conversation) return;

        if (event.target.closest('[data-info-close]')) {
            this.closeInfo();
        } else if (event.target.closest('[data-broadcast-rename]')) {
            this.rename(conversation);
        } else if (event.target.closest('[data-broadcast-edit]')) {
            this.editRecipients(conversation);
        } else if (event.target.closest('[data-broadcast-delete]')) {
            this.remove(conversation);
        }
    }

    async save(conversation, changes, failure) {
        try {
            const updated = this.chat.upsertConversation(await this.chat.api.updateBroadcast(conversation.id, changes));
            if (this.chat.active?.id === updated.id) this.chat.renderHeader(updated);
            this.render(updated);
        } catch (error) {
            toast.error(errorMessage(error, failure));
        }
    }

    rename(conversation) {
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'broadcast-rename-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-rename-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="broadcast-rename-title">Broadcast list name</h2>
                <input class="form-control" name="value" maxlength="100" value="${conversation.broadcast.name ?? ''}" placeholder="e.g. Customers" autocomplete="off">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-rename-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        `;
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };
        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-rename-cancel]')) close();
        });
        overlay.querySelector('form').addEventListener('submit', (event) => {
            event.preventDefault();
            const value = event.target.elements.namedItem('value').value.trim();
            close();
            this.save(conversation, { name: value || null }, "Couldn't rename the list.");
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input').focus();
    }

    async editRecipients(conversation) {
        const current = (conversation.broadcast.recipients ?? []).map((user) => Number(user.id));
        // Everyone on the list, plus contacts and chats that could be added.
        const people = [
            ...(conversation.broadcast.recipients ?? []).map((user) => ({ id: Number(user.id), name: this.chat.displayName(user.id, user.saved_name || user.name), user })),
            ...callablePeople(this.chat, current),
        ].sort((a, b) => a.name.localeCompare(b.name));
        const choice = await pickPeople(this.chat, {
            people,
            title: 'Edit recipients',
            max: Number(conversation.broadcast.max_recipients ?? 256),
            submitLabel: 'Save',
            selected: current,
        });
        if (!choice) return;
        if (choice.ids.length < 2) {
            toast.info('A broadcast list needs at least 2 people.');
            return;
        }
        await this.save(conversation, { user_ids: choice.ids }, "Couldn't change the recipients.");
    }

    async remove(conversation) {
        const choice = await confirmDialog({
            title: `Delete "${broadcastName(conversation.broadcast)}"?`,
            message: 'The list and its messages are removed. Messages already sent stay in each chat.',
            icon: 'trash-2',
            actions: [{ label: 'Delete list', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;

        try {
            await this.chat.api.deleteBroadcast(conversation.id);
            this.closeInfo();
            this.chat.forgetConversation(conversation.id);
            toast.success('Broadcast list deleted.');
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't delete the list."));
        }
    }
}
