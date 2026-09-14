import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatLastSeen } from './format';
import * as T from './templates';

/**
 * Contact info for one-to-one chats (Phase 6): photo, name, About, block and report.
 * Photo, About and last seen follow the other person's privacy settings (the server
 * leaves out what the viewer may not see).
 */
export class ContactInfo {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        this.conversationId = null;

        chat.el.headerUser?.addEventListener('click', () => {
            const conversation = chat.activeConversation();
            if (this.isContact(conversation)) this.open(conversation.id);
        });

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (!this.isContact(conversation)) return;
            items.unshift(html`<button type="button" class="dropdown-item" data-action="contact-info" role="menuitem">${raw(icon('info'))} Contact info</button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'contact-info') this.open(chat.active?.id);
        });
        document.addEventListener('chat:closed', () => this.close());
    }

    isContact(conversation) {
        return conversation?.type === 'direct' && !conversation.is_self && !conversation.is_locked_out && Boolean(conversation.participant);
    }

    async open(conversationId) {
        let conversation = this.chat.conversations.get(Number(conversationId));
        if (!this.isContact(conversation)) return;

        this.close();
        const overlay = document.createElement('div');
        overlay.className = 'group-info contact-info';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Contact info');
        overlay.innerHTML = '<div class="group-info-backdrop" data-info-close></div><aside class="group-info-panel" data-info-body></aside>';
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.conversationId = Number(conversationId);
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onClick(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) this.close();
        };
        document.addEventListener('keydown', this.onKey);

        this.render(conversation);
        overlay.querySelector('[data-info-close].btn-icon')?.focus();

        // Fresh details (About or photo may have changed).
        conversation = (await this.chat.refreshConversation?.(Number(conversationId))) ?? conversation;
        if (this.panel === overlay && conversation) this.render(conversation);
    }

    close() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.conversationId = null;
        this.previousFocus?.focus?.();
    }

    render(conversation) {
        if (!this.panel) return;
        const person = this.chat.participantOf(conversation) ?? conversation.participant;
        const profile = conversation.participant;
        const savedName = person.name !== profile.name ? profile.name : null;
        const presence = conversation.blocked_me || conversation.blocked_by_me
            ? ''
            : person.is_online ? 'online' : formatLastSeen(person.last_seen);

        this.panel.querySelector('[data-info-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-info-close aria-label="Close contact info">${raw(icon('x'))}</button>
                <span class="group-info-title">Contact info</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero">
                    ${raw(T.avatar(person, 'xl'))}
                    <h2 class="group-info-name">${person.name}</h2>
                    <p class="group-info-count">@${profile.username ?? ''}${savedName ? ` · ~${savedName}` : ''}</p>
                    ${raw(presence ? html`<p class="contact-info-presence">${presence}</p>` : '')}
                </section>
                ${raw(profile.about ? html`
                    <section class="group-info-section">
                        <span class="group-info-label">About</span>
                        <p class="group-info-description">${profile.about}</p>
                    </section>` : '')}
                <section class="group-info-section group-info-danger">
                    ${raw(conversation.blocked_by_me
                        ? html`<button type="button" class="group-info-action" data-contact-action="unblock">${raw(icon('undo-2'))} Unblock ${person.name}</button>`
                        : html`<button type="button" class="group-info-action is-danger" data-contact-action="block">${raw(icon('ban'))} Block ${person.name}</button>`)}
                    ${raw(this.chat.api.has('reportUser') ? html`<button type="button" class="group-info-action is-danger" data-contact-action="report-user">${raw(icon('circle-alert'))} Report ${person.name}</button>` : '')}
                </section>
            </div>
        `;
    }

    onClick(event) {
        if (event.target.closest('[data-info-close]')) return this.close();
        const action = event.target.closest('[data-contact-action]')?.dataset.contactAction;
        if (!action) return null;
        this.close();
        document.dispatchEvent(new CustomEvent('chat:action', { detail: { action, chat: this.chat } }));
        return null;
    }
}
