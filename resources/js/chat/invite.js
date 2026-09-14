import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { isNativeApp } from '../lib/native';
import { toast } from '../lib/toast';

/**
 * C8 — Invite friends who are not on the app yet: share a link by WhatsApp,
 * SMS, email or the phone's share sheet, or invite phone contacts directly.
 */

export function inviteMessage({ appName = 'One2One', url, username }) {
    const handle = username ? ` My username is @${username}.` : '';
    return `Hi! I'm using ${appName} to chat and call for free. Join me: ${url}${handle}`;
}

/** Digits of a phone number, keeping a leading + as the international prefix. */
export function dialable(phone) {
    const trimmed = String(phone ?? '').trim();
    const digits = trimmed.replace(/\D/g, '');
    return trimmed.startsWith('+') ? `+${digits}` : digits;
}

/**
 * Links that open another app with the invitation filled in.
 *
 * @param {string} text
 * @param {string|null} phone send straight to this number when given
 */
export function inviteLinks(text, phone = null) {
    const body = encodeURIComponent(text);
    const number = phone ? dialable(phone) : '';
    // wa.me only understands international numbers without "+" or leading zeros.
    const waNumber = number.startsWith('+') ? number.slice(1) : '';

    return {
        whatsapp: `https://wa.me/${waNumber}?text=${body}`,
        sms: `sms:${number}?body=${body}`,
        email: `mailto:?subject=${encodeURIComponent('Chat with me')}&body=${body}`,
    };
}

export class InviteFriends {
    constructor(chat) {
        this.chat = chat;
        this.native = isNativeApp();

        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-invite-friends]')) this.open();
        });
    }

    get text() {
        const { appName, invite, user } = this.chat.config;
        return inviteMessage({ appName, url: invite?.url ?? window.location.origin, username: user?.username });
    }

    /** Open WhatsApp / the SMS app / the mail app with the invitation. */
    launch(url) {
        // Inside the app the WebView hands other schemes and hosts to Android.
        if (this.native || !url.startsWith('https:')) {
            window.location.href = url;
        } else {
            window.open(url, '_blank', 'noopener');
        }
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.text);
        } catch {
            const area = document.createElement('textarea');
            area.value = this.text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        toast.success('Invite link copied.', { timeout: 2000 });
    }

    /**
     * The invite sheet. With a contact, the message goes straight to their number.
     *
     * @param {{name: string, phone: string}|null} contact
     */
    open(contact = null) {
        const text = this.text;
        const links = inviteLinks(text, contact?.phone);
        const canShare = !contact && typeof navigator.share === 'function';
        const previouslyFocused = document.activeElement;

        const option = (value, iconName, label) =>
            html`<button type="button" class="invite-option" data-invite-via="${value}"><span class="invite-option-icon">${raw(icon(iconName))}</span><span>${label}</span></button>`;

        const overlay = document.createElement('div');
        overlay.className = 'modal invite-modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'invite-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-invite-cancel></div>
            <div class="modal-panel invite-panel">
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('user-plus'))}</div>
                <h2 class="modal-title" id="invite-title">${contact ? `Invite ${contact.name || contact.phone}` : 'Invite friends'}</h2>
                <p class="invite-message">${text}</p>
                <div class="invite-options">
                    ${raw(option('whatsapp', 'message-circle', 'WhatsApp'))}
                    ${raw(option('sms', 'message-square-text', 'SMS'))}
                    ${raw(contact ? '' : option('email', 'mail', 'Email'))}
                    ${raw(contact ? '' : option('copy', 'copy', 'Copy link'))}
                    ${raw(canShare ? option('share', 'share-2', 'More') : '')}
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-invite-cancel>Close</button>
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
            if (event.target.closest('[data-invite-cancel]')) return close();
            const via = event.target.closest('[data-invite-via]')?.dataset.inviteVia;
            if (!via) return;

            close();
            if (via === 'copy') return this.copy();
            if (via === 'share') {
                try {
                    await navigator.share({ title: this.chat.config.appName, text });
                } catch {
                    /* cancelled */
                }
                return;
            }
            this.launch(links[via]);
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-invite-via]')?.focus();
    }
}
