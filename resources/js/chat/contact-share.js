import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { isNativeApp } from '../lib/native';
import { toast } from '../lib/toast';

/**
 * M19 — Send a contact card: pick from the phone book (Android app or the
 * browser's contact picker), from your saved contacts, or type one in.
 */

const MAX_PHONES = 5;

/** Keep what a contact card needs: a clean name and up to five numbers. */
export function toCard(entry) {
    const phones = [...new Set((entry.phones ?? [])
        .map((phone) => String(phone ?? '').trim())
        .filter((phone) => /^\+?[0-9 ()\-.]{5,32}$/.test(phone)))]
        .slice(0, MAX_PHONES);
    const name = String(entry.name ?? '').replace(/\s+/g, ' ').trim().slice(0, 100) || phones[0] || '';

    return phones.length ? { name, phones } : null;
}

/** Case-insensitive search by name or digits. */
export function matchesQuery(card, query) {
    const q = query.trim().toLowerCase();
    if (!q) return true;
    const digits = q.replace(/\D/g, '');
    return card.name.toLowerCase().includes(q) || (digits.length >= 3 && card.phones.some((p) => p.replace(/\D/g, '').includes(digits)));
}

export class ContactSharing {
    constructor(chat) {
        this.chat = chat;
    }

    async open() {
        const conversationId = this.chat.active?.id;
        if (!conversationId) return;

        const pickerSupported = !isNativeApp() && typeof navigator.contacts?.select === 'function';
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal contact-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'contact-share-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-contact-cancel></div>
            <div class="modal-panel contact-share-panel">
                <h2 class="modal-title" id="contact-share-title">Send contact</h2>
                <div class="input-wrap">
                    ${raw(icon('search'))}
                    <input type="search" class="form-control" placeholder="Search name or number" aria-label="Search contacts" data-contact-query>
                </div>
                ${raw(pickerSupported ? html`<button type="button" class="btn btn-secondary btn-sm contact-pick" data-contact-pick>${raw(icon('contact'))} Choose from phone</button>` : '')}
                <div class="contact-share-list" data-contact-list><span class="spinner"></span></div>
                <details class="contact-manual">
                    <summary>Type a contact</summary>
                    <form class="contact-manual-form" data-contact-manual>
                        <input class="form-control" name="name" placeholder="Name" maxlength="100" autocomplete="off" required>
                        <input class="form-control" name="phone" placeholder="Phone number" inputmode="tel" maxlength="32" autocomplete="off" required>
                        <button type="submit" class="btn btn-primary btn-sm">Send</button>
                    </form>
                </details>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-contact-cancel>Cancel</button>
                </div>
            </div>
        `;

        let cards = [];
        const list = overlay.querySelector('[data-contact-list]');
        const query = overlay.querySelector('[data-contact-query]');

        const render = () => {
            const shown = cards.filter((card) => matchesQuery(card, query.value)).slice(0, 200);
            list.innerHTML = shown.length
                ? shown.map((card, index) => html`
                    <button type="button" class="contact-share-item" data-contact-index="${cards.indexOf(card)}">
                        <span class="contact-avatar">${initials(card.name)}</span>
                        <span class="contact-share-body"><strong>${card.name}</strong><small>${card.phones[0]}${card.phones.length > 1 ? ` +${card.phones.length - 1}` : ''}</small></span>
                    </button>`).join('')
                : html`<p class="sticker-empty">${cards.length ? 'No contacts match.' : 'No contacts to show. Type one below.'}</p>`;
        };

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };
        const send = (card) => {
            close();
            this.send(conversationId, card);
        };

        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-contact-cancel]')) {
                close();
            } else if (event.target.closest('[data-contact-index]')) {
                send(cards[Number(event.target.closest('[data-contact-index]').dataset.contactIndex)]);
            } else if (event.target.closest('[data-contact-pick]')) {
                try {
                    const [picked] = await navigator.contacts.select(['name', 'tel'], { multiple: false });
                    const card = picked ? toCard({ name: picked.name?.[0], phones: picked.tel }) : null;
                    if (card) send(card);
                    else if (picked) toast.error('This contact has no phone number.');
                } catch {
                    /* picker closed */
                }
            }
        });

        overlay.querySelector('[data-contact-manual]').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = new FormData(event.target);
            const card = toCard({ name: form.get('name'), phones: [form.get('phone')] });
            if (card) send(card);
            else toast.error('Enter a valid phone number.');
        });

        query.addEventListener('input', render);
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        query.focus();

        cards = await this.loadCards();
        if (overlay.isConnected) render();
    }

    /** Phone book in the Android app; otherwise the contacts saved in this account. */
    async loadCards() {
        if (isNativeApp()) {
            try {
                const { NativeApp } = await import('../native/plugins');
                const { contacts } = await NativeApp.getContacts();
                return (contacts ?? []).map(toCard).filter(Boolean).sort((a, b) => a.name.localeCompare(b.name));
            } catch {
                /* permission denied: fall back to saved contacts */
            }
        }

        const saved = this.chat.contactsPanel?.contacts ?? [];
        return saved
            .map((contact) => toCard({ name: contact.name, phones: [contact.phone ?? contact.user?.phone].filter(Boolean) }))
            .filter(Boolean);
    }

    send(conversationId, card) {
        return this.chat.sendSpecial(conversationId, {
            type: 'contact',
            fields: { contact: card },
            attachment: null,
            extra: { contact: { ...card, user: null, vcard_url: null } },
        });
    }
}

function initials(name) {
    return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => [...part][0]).join('').toUpperCase();
}
