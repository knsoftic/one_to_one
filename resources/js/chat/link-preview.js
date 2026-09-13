import { debounce } from '../lib/dom';
import * as T from './templates';

/** Same link rule as formatting.js and the server (LinkPreviewService). */
const URL_PATTERN = /\bhttps?:\/\/[^\s<>"'`]+[^\s<>"'`.,:;!?)\]]/i;

/**
 * The first link in a text, ignoring links inside `code` and ```blocks```.
 */
export function firstUrl(text) {
    const withoutCode = String(text ?? '')
        .replace(/```[\s\S]*?```/g, ' ')
        .replace(/`[^`\n]*`/g, ' ');

    return withoutCode.match(URL_PATTERN)?.[0] ?? null;
}

/**
 * M11 — WhatsApp-style link preview while typing: fetched from the server,
 * shown above the composer and removable (then the message is sent without one).
 */
export class LinkPreviewComposer {
    constructor(chat) {
        this.chat = chat;
        this.container = chat.el.composerExtras.querySelector('[data-link-preview]');
        /** @type {Map<string, object|null>} url → preview (null = the page has none) */
        this.cache = new Map();
        this.dismissed = new Set();
        this.url = null;
        this.preview = null;
        this.request = 0;

        if (!this.container || !chat.api.has('linkPreview')) return;

        this.refreshSoon = debounce(() => this.refresh(), 450);

        document.addEventListener('chat:composing', () => this.refreshSoon());
        document.addEventListener('chat:opened', () => {
            this.reset();
            this.refreshSoon();
        });
        document.addEventListener('chat:closed', () => this.reset());
        document.addEventListener('chat:compose-payload', (event) => this.applyTo(event.detail.payload));

        this.container.addEventListener('click', (event) => {
            if (event.target.closest('[data-link-preview-close]')) this.dismiss();
        });
    }

    async refresh() {
        const editing = this.chat.actions?.mode?.type === 'edit';
        const url = this.chat.active && !editing ? firstUrl(this.chat.el.composerInput.value) : null;
        if (url === this.url) return;

        this.url = url;
        this.preview = null;
        const request = ++this.request;

        if (!url || this.dismissed.has(url)) {
            this.render();
            return;
        }

        if (this.cache.has(url)) {
            this.preview = this.cache.get(url);
            this.render();
            return;
        }

        this.render({ loading: true });

        try {
            const response = await this.chat.api.linkPreview(url);
            const preview = response?.data ?? null;
            this.cache.set(url, preview);
            if (request !== this.request) return;
            this.preview = preview;
        } catch {
            if (request !== this.request) return;
        }

        this.render();
    }

    dismiss() {
        if (this.url) this.dismissed.add(this.url);
        this.request++;
        this.preview = null;
        this.render();
        this.chat.el.composerInput.focus();
    }

    /** Called when a message is being sent: carry the preview along, or turn it off. */
    applyTo(payload) {
        const url = firstUrl(payload?.message);

        if (url && this.dismissed.has(url)) {
            payload.link_preview = false;
        } else if (url && url === this.url && this.preview) {
            payload.link_preview_data = this.preview;
        }

        this.reset();
    }

    reset() {
        this.request++;
        this.url = null;
        this.preview = null;
        this.dismissed.clear();
        this.render();
    }

    render({ loading = false } = {}) {
        if (!this.container) return;

        this.container.innerHTML = loading || this.preview
            ? T.composerLinkPreview({ preview: this.preview, loading, url: this.url })
            : '';
    }
}
