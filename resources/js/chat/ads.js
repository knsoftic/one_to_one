import axios from '../bootstrap';
import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { isNativeApp } from '../lib/native';

/**
 * Ads (Y1). Ads run for everyone — the admin decides whether they run at all and on which
 * screens ("placements"). Each placement names the list it belongs to and where to slot the card,
 * so a screen that is switched off in the admin panel simply never asks for an ad.
 *
 * What the app reports is what the device already exposes: time zone, language, platform and app
 * version, plus a rounded location only while the phone's location permission is granted (the
 * same permission the app asks for alongside contacts and camera).
 */
export class AdsManager {
    constructor(chat) {
        this.chat = chat;
        this.config = chat.config.ads ?? { enabled: false };
        this.routes = chat.config.routes ?? {};
        /** @type {Record<string, object|null>} one ad per placement */
        this.ads = {};
        this.pending = new Set();

        // Opened from a promoted card's web link (promotions.go): open the same way as a tap,
        // whether or not ads are on for this person.
        const target = chat.config.openTarget;
        if (target) setTimeout(() => this.openPromoted(target), 300);

        if (!this.config.enabled) return;

        this.placements = this.config.placements ?? {};
        this.inserting = false;
        this.report();

        this.watchLists();
        this.refresh();
    }

    /**
     * Each screen redraws its own list whenever something changes, and the app switches between
     * them without reloading. Rather than hooking into all of them, watch the containers and the
     * taps that change screen, then put the card back where it belongs.
     */
    watchLists() {
        const schedule = () => {
            cancelAnimationFrame(this.frame);
            this.frame = requestAnimationFrame(() => this.refresh());
        };

        const observer = new MutationObserver(() => {
            if (!this.inserting) schedule();
        });

        for (const selector of new Set(Object.values(this.placements).map((spec) => spec.container))) {
            const el = document.querySelector(selector);
            if (el) observer.observe(el, { childList: true });
        }

        document.addEventListener('chat:conversations-rendered', schedule);
        // Moving between Chats, Status, Communities and Calls does not redraw anything.
        document.addEventListener('click', (event) => {
            if (event.target.closest('[data-mobile-tab], .app-rail-item, .conversation-item')) schedule();
        });
    }

    /**
     * Ask for an ad only for the screens the person is actually looking at. Asking for all of them
     * at once would spend the whole daily limit on screens they never opened.
     */
    refresh() {
        for (const [placement, spec] of Object.entries(this.placements)) {
            if (!this.onScreen(spec.container)) continue;
            if (this.ads[placement] === undefined) this.load(placement);
            else this.placeSafely(placement);
        }
    }

    onScreen(selector) {
        const el = document.querySelector(selector);

        return Boolean(el && el.getClientRects().length);
    }

    /** What the device can say without asking the user anything beyond the system permission. */
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

    /** Tell the server the app was opened, with whatever the device knows. */
    async report() {
        if (!this.routes.adsOpen) return;
        try {
            await axios.post(this.routes.adsOpen, { ...this.deviceContext(), ...(await this.location()) });
        } catch {
            /* the ads still work without it */
        }
    }

    /**
     * A rounded position, but only when the phone's location permission has already been granted
     * (through the same prompt as contacts and camera). This never brings up a prompt of its own.
     */
    async location() {
        try {
            const status = await navigator.permissions?.query({ name: 'geolocation' });
            if (status?.state !== 'granted') return { location_allowed: false };

            const position = await new Promise((resolve, reject) =>
                navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: false, timeout: 8000, maximumAge: 600_000 }),
            );

            return { location_allowed: true, lat: position.coords.latitude, lng: position.coords.longitude };
        } catch {
            return { location_allowed: false };
        }
    }

    async load(placement) {
        if (!this.routes.adsNext || this.pending.has(placement)) return;
        this.pending.add(placement);
        try {
            const { data } = await axios.get(this.routes.adsNext, { params: { placement } });
            this.ads[placement] = data.ad ?? null;
            if (this.ads[placement]) this.placeSafely(placement);
        } catch {
            this.ads[placement] = null;
        } finally {
            this.pending.delete(placement);
        }
    }

    /** Slot the card in without the container's own observer treating it as a redraw. */
    placeSafely(placement) {
        this.inserting = true;
        try {
            this.place(placement);
        } finally {
            requestAnimationFrame(() => {
                this.inserting = false;
            });
        }
    }

    /** Put (or move) the card into the screen this placement belongs to. */
    place(placement) {
        const spec = this.placements[placement];
        const ad = this.ads[placement];
        if (!spec || !ad) return;

        const container = document.querySelector(spec.container);
        const slot = this.card(placement, ad);
        if (!container) return;

        // The chat list keeps archived and locked folders free of ads.
        const mode = this.chat.listMode;
        if (placement === 'chat_list' && (mode === 'archived' || mode === 'locked')) {
            slot.remove();
            return;
        }

        if (spec.format === 'banner') {
            container.prepend(slot);
            return;
        }

        const rows = [...container.querySelectorAll(spec.item)];
        if (rows.length < 2) {
            slot.remove();
            return;
        }

        const every = Math.max(4, this.config.every ?? 6);
        // Re-inserting the same node just moves it, so there is never a duplicate.
        rows[Math.min(every - 1, rows.length - 1)].after(slot);
    }

    card(placement, ad) {
        const existing = document.querySelector(`.ad-slot[data-placement="${placement}"]`);
        if (existing) return existing;

        const slot = document.createElement('div');
        slot.className = 'ad-slot';
        slot.dataset.placement = placement;
        slot.innerHTML = adCardHtml(ad);
        // A promoted status, channel, community or business (Y2) opens inside the app: the link
        // stays as the no-JS fallback, the tap is recorded and the real screen opens.
        slot.addEventListener('click', (event) => {
            if (!event.target.closest('[data-ad-internal]')) return;
            event.preventDefault();
            this.tap(placement, ad);
        });
        return slot;
    }

    /** Record the tap on a promoted card and open what it promotes (Y2). */
    async tap(placement, ad) {
        const route = this.routes.adsTap?.replace('__ID__', String(ad.id));
        let target = null;
        if (route) {
            try {
                const { data } = await axios.post(route, { placement });
                target = data?.open ?? (data?.url ? { type: 'url', url: data.url } : null);
            } catch {
                /* recorded or not, the person still gets where they tapped */
            }
        }
        this.openPromoted(target ?? { type: 'url', url: ad.url ?? ad.click });
    }

    /**
     * Open a promoted target through the app's own screens — the status viewer, the channel
     * preview, the community join sheet or the business chat. Anything the app cannot open
     * in place (or a missing manager) falls back to the plain page.
     */
    openPromoted(target) {
        const chat = this.chat;
        const fallback = (url) => {
            if (url) window.location.assign(url);
        };
        if (!target) return null;

        switch (target.type) {
            case 'status':
                if (chat.statuses?.openViewer && target.status && target.user) {
                    return chat.statuses.openViewer([{ user: target.user, statuses: [target.status] }], 0, 0);
                }
                break;
            case 'channel':
                if (chat.channels?.preview) return chat.channels.preview(Number(target.id));
                break;
            case 'community':
                if (chat.communities?.offerToJoin && target.invite) return chat.communities.offerToJoin(target.invite);
                break;
            case 'business':
                if (chat.startConversationWith) {
                    return Promise.resolve(chat.startConversationWith(Number(target.user_id))).then(() => {
                        if (chat.active?.id && chat.contactInfo?.open) chat.contactInfo.open(chat.active.id);
                    });
                }
                break;
            default:
                break;
        }
        return fallback(target.url);
    }
}

/**
 * The link to Settings › Promote with a target preselected (Y2), or null when paid promotions
 * are off for this app. Used by the status viewer, channel info and community info.
 */
export function promoteLink(kind, id) {
    const app = window.App?.config;
    if (!app?.paid?.promote || !app.routes?.settings) return null;

    return `${app.routes.settings}?tab=promote&kind=${encodeURIComponent(kind)}&id=${encodeURIComponent(id)}`;
}

/**
 * The card markup, shared with the Promote screen's preview (resources/js/ui/promote.js). A
 * promotion says "Promoted · name"; a house ad says "Sponsored". Internal kinds carry
 * `data-ad-internal` and no target so the tap can be handled in the app; external ones open in
 * a new tab (the native shell hands them to the browser).
 */
export function adCardHtml(ad) {
    const promoted = Boolean(ad.promoted);
    const internal = Boolean(ad.internal);
    const sponsor = ad.sponsor ? ` · ${ad.sponsor}` : '';
    const tag = promoted ? `Promoted${sponsor}` : `Sponsored${sponsor}`;
    const href = ad.click ?? ad.url ?? '#';
    const attrs = internal ? html`data-ad-internal` : html`target="_blank" rel="noopener nofollow sponsored"`;

    return html`
        <a class="ad-card ad-card-${ad.format ?? 'row'}${promoted ? ' is-promoted' : ''}" href="${href}" ${raw(attrs)} data-ad-card="${ad.id ?? ''}" aria-label="${promoted ? 'Promoted' : 'Sponsored'}: ${ad.title}">
            ${ad.image ? raw(html`<span class="ad-card-media" style="background-image:url('${ad.image}')"></span>`) : ''}
            <span class="ad-card-body">
                <span class="ad-card-tag">${tag}</span>
                <span class="ad-card-title">${ad.title}</span>
                ${ad.body ? raw(html`<span class="ad-card-text">${ad.body}</span>`) : ''}
                <span class="ad-card-cta">${ad.cta} ${raw(icon(internal ? 'arrow-right' : 'square-arrow-out-up-right'))}</span>
            </span>
        </a>
    `;
}
