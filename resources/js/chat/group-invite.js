import qrcode from 'qrcode-generator';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import * as T from './templates';

/**
 * G3 — Group invite link and QR code: admins share or reset it; opening it offers to join.
 */

/** QR code of a link as an SVG (scales to its box, dark modules on a light ground). */
export function qrSvg(text) {
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
}

export class GroupInvites {
    constructor(chat) {
        this.chat = chat;
        if (!chat.api.has('groupInvite')) return;

        const invite = chat.config.groupInvite;
        if (invite) setTimeout(() => this.offerToJoin(invite), 300);
    }

    /* ------------------------------------------------------------------ */
    /* Admins: link, QR, share, reset                                      */
    /* ------------------------------------------------------------------ */

    async open(conversation) {
        return this.openLink({
            name: conversation.group.name,
            kind: 'group',
            load: () => this.chat.api.groupInvite(conversation.id),
            reset: () => this.chat.api.resetGroupInvite(conversation.id),
        });
    }

    /**
     * Invite dialog for a group, a community (G10) or a channel (G11): link, QR code, copy, share, reset.
     *
     * @param {{name: string, kind: 'group'|'community'|'channel', load: () => Promise<{url: string}>, reset: (() => Promise<{url: string}>)|null}} options
     */
    async openLink({ name, kind, load, reset }) {
        let link;
        try {
            link = await load();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't load the invite link."));
            return;
        }

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal group-invite';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'group-invite-title');

        const render = () => {
            overlay.innerHTML = html`
                <div class="modal-backdrop" data-invite-close></div>
                <div class="modal-panel group-invite-panel">
                    <h2 class="modal-title" id="group-invite-title">${kind === 'channel' ? 'Channel link' : `Invite to ${kind} via link`}</h2>
                    <p class="group-invite-hint">${kind === 'channel' ? `Anyone with this link can see "${name}" and follow it.` : `Anyone with this link can join "${name}". Only share it with people you trust.`}</p>
                    <div class="group-invite-qr" aria-label="QR code of the invite link" role="img">${raw(qrSvg(link.url))}</div>
                    <div class="group-invite-link"><span data-invite-url>${link.url}</span></div>
                    <div class="group-invite-actions">
                        <button type="button" class="group-info-action" data-invite-copy>${raw(icon('copy'))} Copy link</button>
                        <button type="button" class="group-info-action" data-invite-share>${raw(icon('share-2'))} Share link</button>
                        ${raw(reset ? html`<button type="button" class="group-info-action is-danger" data-invite-reset>${raw(icon('refresh-cw'))} Reset link</button>` : '')}
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-primary" data-invite-close>Done</button>
                    </div>
                </div>
            `;
        };

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-invite-close]')) return close();
            if (event.target.closest('[data-invite-copy]')) return this.copy(link.url);
            if (event.target.closest('[data-invite-share]')) return this.share({ group: { name } }, link.url, kind);
            if (reset && event.target.closest('[data-invite-reset]')) {
                const choice = await confirmDialog({
                    title: 'Reset this link?',
                    message: `The current link stops working. People who already joined stay in the ${kind}.`,
                    icon: 'refresh-cw',
                    actions: [{ label: 'Reset link', value: 'reset', variant: 'danger' }],
                });
                if (choice !== 'reset') return null;
                try {
                    link = await reset();
                    render();
                    toast.success('A new invite link was made.');
                } catch (error) {
                    toast.error(errorMessage(error, "Couldn't reset the link."));
                }
            }
            return null;
        });

        render();
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-invite-copy]')?.focus();
    }

    async copy(url) {
        try {
            await navigator.clipboard.writeText(url);
        } catch {
            const area = Object.assign(document.createElement('textarea'), { value: url });
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        toast.success('Invite link copied.', { timeout: 2000 });
    }

    async share(conversation, url, kind = 'group') {
        const app = this.chat.config.appName ?? 'One2One';
        const text = kind === 'channel'
            ? `Follow the channel "${conversation.group.name}" on ${app}: ${url}`
            : `Join my ${kind} "${conversation.group.name}" on ${app}: ${url}`;
        if (typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: conversation.group.name, text });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') return;
            }
        }
        if (this.chat.invite?.launch) this.chat.invite.launch(`https://wa.me/?text=${encodeURIComponent(text)}`);
        else this.copy(url);
    }

    /* ------------------------------------------------------------------ */
    /* Opening a link                                                      */
    /* ------------------------------------------------------------------ */

    async offerToJoin(invite) {
        // Keep the address of the chat page, so reloading does not ask again.
        history.replaceState(history.state, '', this.chat.config.routes.chat);

        if (!invite.valid) {
            toast.error('This invite link is no longer valid. Ask a group admin for a new one.');
            return;
        }
        if (invite.is_member) {
            this.chat.openConversation(invite.conversation_id);
            return;
        }

        const group = { id: `group-${invite.conversation_id}`, name: invite.name, avatar_url: invite.avatar_url, initials: invite.initials, avatar_hue: invite.avatar_hue, is_group: true };
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal group-join';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'group-join-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-join-cancel></div>
            <div class="modal-panel group-join-panel">
                ${raw(T.avatar(group, 'xl'))}
                <h2 class="modal-title" id="group-join-title">${invite.name}</h2>
                <p class="group-join-count">Group · ${invite.member_count === 1 ? '1 member' : `${invite.member_count} members`}</p>
                ${raw(invite.description ? html`<p class="group-join-description">${invite.description}</p>` : '')}
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-join-cancel>Not now</button>
                    <button type="button" class="btn btn-primary" data-join>Join group</button>
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

        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-join-cancel]')) return close();
            const join = event.target.closest('[data-join]');
            if (!join) return null;
            join.disabled = true;
            try {
                const conversation = await this.chat.api.joinGroup(invite.token);
                close();
                this.chat.upsertConversation(conversation);
                this.chat.openConversation(conversation.id);
            } catch (error) {
                join.disabled = false;
                toast.error(errorMessage(error, "Couldn't join the group."));
            }
            return null;
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-join]').focus();
    }
}
