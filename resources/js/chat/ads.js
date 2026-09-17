import axios from '../bootstrap';
import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { isNativeApp } from '../lib/native';

/**
 * Ads (Y1): the first-run "Personalised ads" choice, and the sponsored card in the chat list.
 * Does nothing unless the admin turned ads on. Targeting is decided on the server and only for
 * users who opted in; this file never reads anything sensitive from the device.
 */
export class AdsManager {
    constructor(chat) {
        this.chat = chat;
        this.config = chat.config.ads ?? { enabled: false };
        this.routes = chat.config.routes ?? {};
        this.ad = null;

        if (!this.config.enabled) return;

        document.addEventListener('chat:conversations-rendered', () => this.place());
        if (!this.config.decided) {
            // Ask for the choice once, after the chats have loaded.
            setTimeout(() => this.askConsent(), 1500);
        }
        this.load();
    }

    /** What the device can tell us without any permission — used only if the user opts in. */
    deviceContext() {
        let timezone = '';
        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone ?? '';
        } catch {
            /* older browsers */
        }
        return {
            timezone,
            locale: (navigator.language || '').slice(0, 12),
            platform: isNativeApp() ? 'android' : 'web',
        };
    }

    /** The one-time choice. Declining (or dismissing) keeps ads non-personalised. */
    async askConsent() {
        if (this.config.decided || !this.routes.adsConsent) return;
        const choice = await confirmDialog({
            title: 'Personalised ads',
            message:
                'This app is free, with ads. May we use your country, what you do in the app and device basics to show more relevant ads? You can change this any time in Settings › Privacy. We never read your contacts, your messages or your exact location.',
            icon: 'badge-dollar-sign',
            tone: 'primary',
            actions: [
                { label: 'No, generic ads', value: 'off', variant: 'secondary' },
                { label: 'Yes, personalise', value: 'on', variant: 'primary' },
            ],
            cancelLabel: null,
        });
        await this.setConsent(choice === 'on');
    }

    async setConsent(personalised) {
        this.config.decided = true;
        try {
            const { data } = await axios.post(this.routes.adsConsent, { personalised, ...this.deviceContext() });
            this.config.personalised = Boolean(data.personalised);
        } catch {
            /* the choice can be made again in Settings */
        }
    }

    async load() {
        if (!this.routes.adsNext) return;
        try {
            const { data } = await axios.get(this.routes.adsNext);
            this.ad = data.ad ?? null;
            if (this.ad) this.place();
        } catch {
            this.ad = null;
        }
    }

    /** Put (or move) the sponsored card into the chat list, a few rows down. */
    place() {
        const list = this.chat.el?.conversationList;
        if (!list || !this.ad) return;

        const mode = this.chat.listMode;
        if (mode === 'archived' || mode === 'locked') {
            list.querySelector('.ad-slot')?.remove();
            return;
        }

        const rows = [...list.querySelectorAll('.conversation-item')];
        if (rows.length < 2) {
            list.querySelector('.ad-slot')?.remove();
            return;
        }

        const every = Math.max(4, this.config.everyChats ?? 6);
        // Re-inserting the same node just moves it, so there is never a duplicate.
        rows[Math.min(every - 1, rows.length - 1)].after(this.card());
    }

    card() {
        const existing = document.querySelector('.ad-slot');
        if (existing) return existing;

        const slot = document.createElement('div');
        slot.className = 'ad-slot';
        // A same-origin tracking link that records the tap and forwards to the advertiser; opening
        // in a new tab keeps the chat open (and the app hands external hosts to the browser).
        slot.innerHTML = html`
            <a class="ad-card" href="${this.ad.click}" target="_blank" rel="noopener nofollow sponsored" aria-label="Sponsored: ${this.ad.title}">
                ${this.ad.image ? raw(html`<span class="ad-card-media" style="background-image:url('${this.ad.image}')"></span>`) : ''}
                <span class="ad-card-body">
                    <span class="ad-card-tag">Sponsored${this.ad.sponsor ? ` · ${this.ad.sponsor}` : ''}</span>
                    <span class="ad-card-title">${this.ad.title}</span>
                    ${this.ad.body ? raw(html`<span class="ad-card-text">${this.ad.body}</span>`) : ''}
                    <span class="ad-card-cta">${this.ad.cta} ${raw(icon('square-arrow-out-up-right'))}</span>
                </span>
            </a>
        `;
        return slot;
    }
}
