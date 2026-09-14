import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

/**
 * K7 — Call links: create a link, send it to anyone, and join the call from it.
 */

export function callLinkMessage(link, appName = 'One2One') {
    const kind = link.type === 'video' ? 'video call' : 'voice call';
    return `Join my ${kind} on ${appName}: ${link.url}`;
}

export class CallLinks {
    constructor(chat) {
        this.chat = chat;
        if (!chat.api.has('callLinks') || !chat.calls?.group) {
            document.querySelectorAll('[data-action="call-links"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'call-links') this.open();
        });

        // Opened from a call link.
        const link = chat.config.callLink;
        if (link) setTimeout(() => this.offerToJoin(link), 300);
    }

    async offerToJoin(link) {
        // Keep the address of the chat page, so reloading does not ask again.
        history.replaceState(history.state, '', this.chat.config.routes.chat);

        if (!link.valid) {
            toast.error('This call link is no longer valid.');
            return;
        }

        const owner = this.chat.decorate?.(link.owner ?? {}) ?? link.owner ?? {};
        const kind = link.type === 'video' ? 'video call' : 'voice call';
        const choice = await confirmDialog({
            title: link.is_mine ? `Start your ${kind}?` : `Join ${owner.name ?? 'the'}'s ${kind}?`,
            message: link.is_mine
                ? 'People who open your link join you here.'
                : `${owner.name ?? 'They'} will be called when you join. Up to 4 people can take part.`,
            icon: link.type === 'video' ? 'video' : 'phone',
            tone: 'primary',
            cancelLabel: 'Not now',
            actions: [{ label: 'Join', value: 'join', variant: 'primary' }],
        });
        if (choice === 'join') this.chat.calls.group.joinLink(link.token, link.type);
    }

    async open() {
        let links = [];
        try {
            links = (await this.chat.api.callLinks()).data ?? [];
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't load your call links."));
            return;
        }
        this.showDialog(links);
    }

    showDialog(links) {
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal call-links-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'call-links-title');

        const row = (link) => html`
            <div class="call-link-row" data-link="${link.token}">
                <span class="call-link-icon">${raw(icon(link.type === 'video' ? 'video' : 'phone'))}</span>
                <span class="call-link-body">
                    <span class="call-link-kind">${link.type === 'video' ? 'Video call link' : 'Voice call link'}</span>
                    <span class="call-link-url">${link.url}</span>
                </span>
                <button type="button" class="btn-icon btn-icon-sm" data-link-copy aria-label="Copy link" title="Copy link">${raw(icon('copy'))}</button>
                <button type="button" class="btn-icon btn-icon-sm" data-link-share aria-label="Send link" title="Send link">${raw(icon('share-2'))}</button>
                <button type="button" class="btn-icon btn-icon-sm" data-link-delete aria-label="Delete link" title="Delete link">${raw(icon('trash-2'))}</button>
            </div>`;

        overlay.innerHTML = html`
            <div class="modal-backdrop" data-links-close></div>
            <div class="modal-panel call-links-panel">
                <h2 class="modal-title" id="call-links-title">Call links</h2>
                <p class="call-links-hint">Anyone with a link can join a call with you (up to 4 people). Send it on WhatsApp, SMS or email.</p>
                <div class="call-links-list" data-links-list>${raw(links.map(row).join('') || html`<p class="sticker-empty">No call links yet.</p>`)}</div>
                <div class="call-links-create">
                    <button type="button" class="btn btn-secondary" data-link-create="audio">${raw(icon('phone'))} New voice link</button>
                    <button type="button" class="btn btn-secondary" data-link-create="video">${raw(icon('video'))} New video link</button>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary" data-links-close>Done</button>
                </div>
            </div>
        `;

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };
        const list = overlay.querySelector('[data-links-list]');
        const find = (el) => links.find((link) => link.token === el.closest('[data-link]')?.dataset.link);

        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-links-close]')) return close();

            const create = event.target.closest('[data-link-create]');
            if (create) {
                create.disabled = true;
                try {
                    const link = await this.chat.api.createCallLink(create.dataset.linkCreate);
                    links = [link, ...links];
                    list.innerHTML = links.map(row).join('');
                    await this.copy(link);
                } catch (error) {
                    toast.error(errorMessage(error, "Couldn't create the link."));
                } finally {
                    create.disabled = false;
                }
                return;
            }

            const link = find(event.target);
            if (!link) return;
            if (event.target.closest('[data-link-copy]')) this.copy(link);
            if (event.target.closest('[data-link-share]')) this.share(link);
            if (event.target.closest('[data-link-delete]')) {
                const choice = await confirmDialog({
                    title: 'Delete this link?',
                    message: 'People who have it will no longer be able to join.',
                    icon: 'trash-2',
                    actions: [{ label: 'Delete', value: 'delete', variant: 'danger' }],
                });
                if (choice !== 'delete') return;
                try {
                    await this.chat.api.deleteCallLink(link.token);
                    links = links.filter((entry) => entry.token !== link.token);
                    list.innerHTML = links.map(row).join('') || html`<p class="sticker-empty">No call links yet.</p>`;
                } catch (error) {
                    toast.error(errorMessage(error, "Couldn't delete the link."));
                }
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-link-create]')?.focus();
    }

    async copy(link) {
        const text = link.url;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        toast.success('Call link copied.', { timeout: 2000 });
    }

    async share(link) {
        const text = callLinkMessage(link, this.chat.config.appName);
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: this.chat.config.appName, text });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') return;
            }
        }
        if (this.chat.invite) {
            this.chat.invite.launch(`https://wa.me/?text=${encodeURIComponent(text)}`);
        } else {
            this.copy(link);
        }
    }
}

