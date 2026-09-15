import { errorMessage, formatBytes, formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatListTime } from './format';
import { openLightbox } from './lightbox';
import { openVideoPlayer } from './video';
import * as T from './templates';

const TABS = [
    { kind: 'media', label: 'Media', empty: { iconName: 'images', title: 'No photos or videos', text: 'Photos and videos shared in this chat show up here.' } },
    { kind: 'docs', label: 'Docs', empty: { iconName: 'file-text', title: 'No documents', text: 'PDFs, spreadsheets and other files shared in this chat show up here.' } },
    { kind: 'links', label: 'Links', empty: { iconName: 'link', title: 'No links', text: 'Web links shared in this chat show up here.' } },
];

const monthName = new Intl.DateTimeFormat(undefined, { month: 'long' });
const monthYear = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric' });

/** "This month", "August" or "December 2025" — media is grouped by month. */
export function monthLabel(value, now = new Date()) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    if (date.getFullYear() === now.getFullYear()) {
        return date.getMonth() === now.getMonth() ? 'This month' : monthName.format(date);
    }
    return monthYear.format(date);
}

const hostOf = (url) => {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
};

/**
 * D1 — Media, links and docs of a chat: every photo and video, document and
 * link the user can still see, newest first, with "Show in chat".
 */
export class MediaGallery {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        this.conversationId = null;
        this.tab = 'media';
        this.tabs = {};
        this.counts = null;

        if (!chat.api.has('conversationGallery')) return;

        document.addEventListener('chat:menu', (event) => {
            if (!this.canOpen(event.detail.conversation)) return;
            event.detail.items.push(html`<button type="button" class="dropdown-item" data-action="open-media" role="menuitem">${raw(icon('images'))} Media, links and docs</button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'open-media') this.open(this.chat.active?.id);
        });
        document.addEventListener('chat:closed', () => this.close());
    }

    get isOpen() {
        return Boolean(this.panel);
    }

    canOpen(conversation) {
        return Boolean(conversation) && !conversation.is_locked_out;
    }

    open(conversationId, tab = 'media') {
        const conversation = this.chat.conversations.get(Number(conversationId));
        if (!this.canOpen(conversation)) return;

        this.close();
        this.conversationId = Number(conversationId);
        this.tab = TABS.some((t) => t.kind === tab) ? tab : 'media';
        this.tabs = Object.fromEntries(TABS.map((t) => [t.kind, { items: [], hasMore: false, loaded: false, loading: false, error: null }]));
        this.counts = null;
        this.previousFocus = document.activeElement;

        const person = this.chat.participantOf(conversation) ?? {};
        const overlay = document.createElement('div');
        overlay.className = 'group-info media-gallery';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Media, links and docs');
        overlay.innerHTML = html`
            <div class="group-info-backdrop" data-gallery-close></div>
            <aside class="group-info-panel">
                <header class="group-info-header">
                    <button type="button" class="btn-icon" data-gallery-close aria-label="Close media, links and docs">${raw(icon('arrow-left'))}</button>
                    <span class="gallery-heading">
                        <span class="group-info-title">Media, links and docs</span>
                        <span class="gallery-chat-name">${person.name ?? ''}</span>
                    </span>
                </header>
                <div class="gallery-tabs" role="tablist" aria-label="What to show">
                    ${raw(TABS.map((t) => html`<button type="button" class="gallery-tab" role="tab" id="gallery-tab-${t.kind}" data-gallery-tab="${t.kind}" aria-controls="gallery-list">${t.label}<span class="gallery-tab-count" data-gallery-count="${t.kind}"></span></button>`).join(''))}
                </div>
                <div class="group-info-scroll gallery-scroll" data-gallery-scroll>
                    <div id="gallery-list" class="gallery-list" role="tabpanel" data-gallery-list></div>
                </div>
            </aside>
        `;
        document.body.appendChild(overlay);
        this.panel = overlay;

        overlay.addEventListener('click', (event) => this.onClick(event));
        this.onKey = (event) => {
            if (event.key !== 'Escape' || document.querySelector('.modal, .lightbox:not(.is-closing)')) return;
            this.close();
        };
        document.addEventListener('keydown', this.onKey);
        overlay.querySelector('[data-gallery-scroll]').addEventListener('scroll', () => this.onScroll(), { passive: true });

        this.selectTab(this.tab);
        overlay.querySelector('.btn-icon[data-gallery-close]')?.focus();
    }

    close() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.conversationId = null;
        this.previousFocus?.focus?.();
    }

    selectTab(kind) {
        if (!this.panel) return;
        this.tab = kind;
        this.panel.querySelectorAll('[data-gallery-tab]').forEach((button) => {
            const active = button.dataset.galleryTab === kind;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', String(active));
        });
        this.panel.querySelector('[data-gallery-list]').setAttribute('aria-labelledby', `gallery-tab-${kind}`);
        this.panel.querySelector('[data-gallery-scroll]').scrollTop = 0;

        const state = this.tabs[kind];
        if (state.loaded || state.error) this.render();
        else this.load(kind);
    }

    async load(kind) {
        const state = this.tabs[kind];
        if (!state || state.loading) return;
        const conversationId = this.conversationId;
        state.loading = true;
        state.error = null;
        this.render();

        try {
            const before = state.items.at(-1)?.id ?? null;
            const response = await this.chat.api.gallery(conversationId, kind, before);
            if (!this.panel || this.conversationId !== conversationId) return;

            state.items = [...state.items, ...(response.data ?? [])];
            state.hasMore = Boolean(response.has_more);
            state.loaded = true;
            if (response.counts) this.setCounts(response.counts);
        } catch (error) {
            state.error = errorMessage(error, 'Could not load this list.');
        } finally {
            state.loading = false;
            if (this.panel && this.conversationId === conversationId && this.tab === kind) this.render();
        }
    }

    setCounts(counts) {
        this.counts = counts;
        this.panel?.querySelectorAll('[data-gallery-count]').forEach((badge) => {
            const count = Number(counts[badge.dataset.galleryCount] ?? 0);
            badge.textContent = count ? String(count) : '';
        });
    }

    onScroll() {
        const scroll = this.panel?.querySelector('[data-gallery-scroll]');
        const state = this.tabs[this.tab];
        if (!scroll || !state?.hasMore || state.loading) return;
        if (scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 300) this.load(this.tab);
    }

    /* ------------------------------------------------------------------ */

    render() {
        const list = this.panel?.querySelector('[data-gallery-list]');
        if (!list) return;
        const state = this.tabs[this.tab];
        const tab = TABS.find((t) => t.kind === this.tab);
        list.dataset.kind = this.tab;

        if (!state.items.length) {
            if (state.error) {
                list.innerHTML = html`${raw(T.emptyState({ iconName: 'circle-alert', title: "Couldn't load this list", text: state.error }))}<div class="gallery-retry"><button type="button" class="btn btn-secondary" data-gallery-retry>Try again</button></div>`;
            } else if (state.loading || !state.loaded) {
                list.innerHTML = '<div class="gallery-loading"><span class="spinner"></span></div>';
            } else {
                list.innerHTML = T.emptyState(tab.empty);
            }
            return;
        }

        const body = this.tab === 'media' ? this.mediaGrid(state.items) : this.tab === 'docs' ? this.docRows(state.items) : this.linkRows(state.items);
        const footer = state.loading
            ? '<div class="gallery-loading"><span class="spinner"></span></div>'
            : state.error
              ? html`<div class="gallery-retry"><button type="button" class="btn btn-secondary" data-gallery-retry>Load more</button></div>`
              : state.hasMore
                ? html`<div class="gallery-retry"><button type="button" class="btn btn-ghost" data-gallery-more>Load more</button></div>`
                : '';
        list.innerHTML = body + footer;
    }

    mediaGrid(items) {
        const groups = [];
        for (const message of items) {
            const label = monthLabel(message.created_at);
            if (groups.at(-1)?.label !== label) groups.push({ label, items: [] });
            groups.at(-1).items.push(message);
        }

        return groups
            .map((group) => html`
                <section class="gallery-group">
                    <h3 class="gallery-group-label">${group.label}</h3>
                    <div class="gallery-grid">${raw(group.items.map((message) => this.mediaTile(message)).join(''))}</div>
                </section>
            `)
            .join('');
    }

    mediaTile(message) {
        const a = message.attachment ?? {};
        const video = message.type === 'video';
        const src = a.thumbnail_url || (video ? '' : a.url);
        const gif = !video && (a.animated || a.mime === 'image/gif');

        return html`
            <button type="button" class="gallery-tile${raw(video ? ' is-video' : '')}" data-gallery-open="${message.id}" aria-label="${video ? 'Play video' : 'Open photo'}${message.body ? `: ${message.body}` : ''}">
                ${raw(src ? html`<img src="${src}" alt="" loading="lazy" decoding="async">` : `<span class="gallery-tile-placeholder">${icon(video ? 'film' : 'image')}</span>`)}
                ${raw(video ? html`<span class="gallery-tile-badge">${raw(icon('play'))}${a.duration ? formatDuration(a.duration) : ''}</span>` : '')}
                ${raw(gif ? '<span class="gallery-tile-badge">GIF</span>' : '')}
            </button>
        `;
    }

    docRows(items) {
        return items
            .map((message) => {
                const a = message.attachment ?? {};
                const extension = String(a.name || '').split('.').pop().toLowerCase();
                const kind = T.fileKind(extension);
                return html`
                    <div class="gallery-row">
                        <a class="gallery-row-main" href="${a.download_url || '#'}" download="${a.name || ''}" title="Download ${a.name || 'file'}">
                            <span class="message-file-icon" data-ext="${extension}" data-kind="${kind.kind}">${raw(icon(kind.icon))}</span>
                            <span class="gallery-row-body">
                                <span class="gallery-row-title">${a.name || 'Document'}</span>
                                <span class="gallery-row-meta">${[extension.toUpperCase(), formatBytes(a.size), formatListTime(message.created_at)].filter(Boolean).join(' · ')}</span>
                            </span>
                        </a>
                        ${raw(this.showInChatButton(message))}
                    </div>
                `;
            })
            .join('');
    }

    linkRows(items) {
        return items
            .map((message) => {
                const preview = message.link_preview;
                const links = message.links?.length ? message.links : preview?.url ? [preview.url] : [];
                if (!links.length) return '';
                const [first, ...rest] = links;
                const card = preview && preview.url === first ? preview : null;

                return html`
                    <div class="gallery-row is-link">
                        <a class="gallery-row-main" href="${first}" target="_blank" rel="noopener noreferrer nofollow">
                            ${raw(card?.image_url
                                ? html`<span class="gallery-link-thumb"><img src="${card.image_url}" alt="" loading="lazy" decoding="async"></span>`
                                : html`<span class="gallery-link-thumb is-icon">${raw(icon('link'))}</span>`)}
                            <span class="gallery-row-body">
                                <span class="gallery-row-title">${card?.title || hostOf(first)}</span>
                                <span class="gallery-row-link">${first}</span>
                                <span class="gallery-row-meta">${formatListTime(message.created_at)}</span>
                            </span>
                        </a>
                        ${raw(this.showInChatButton(message))}
                        ${raw(rest.length ? html`<div class="gallery-more-links">${raw(rest.map((url) => html`<a href="${url}" target="_blank" rel="noopener noreferrer nofollow">${raw(icon('external-link'))}<span>${url}</span></a>`).join(''))}</div>` : '')}
                    </div>
                `;
            })
            .join('');
    }

    showInChatButton(message) {
        return html`<button type="button" class="btn-icon btn-icon-sm gallery-row-jump" data-gallery-jump="${message.id}" aria-label="Show in chat" title="Show in chat">${raw(icon('message-square'))}</button>`;
    }

    /* ------------------------------------------------------------------ */

    onClick(event) {
        const target = event.target;
        if (target.closest('[data-gallery-close]')) return this.close();

        const tab = target.closest('[data-gallery-tab]');
        if (tab) return this.selectTab(tab.dataset.galleryTab);

        if (target.closest('[data-gallery-more], [data-gallery-retry]')) return this.load(this.tab);

        const jump = target.closest('[data-gallery-jump]');
        if (jump) return this.showInChat(Number(jump.dataset.galleryJump));

        const tile = target.closest('[data-gallery-open]');
        if (tile) return this.openMedia(Number(tile.dataset.galleryOpen));

        return null;
    }

    openMedia(messageId) {
        const message = this.tabs.media.items.find((item) => Number(item.id) === messageId);
        const a = message?.attachment;
        if (!a?.url) return;
        const onShowInChat = () => this.showInChat(messageId);

        if (message.type === 'video') {
            openVideoPlayer({ src: a.url, name: a.name || 'Video', download: a.download_url, poster: a.thumbnail_url || '', onShowInChat });
        } else {
            openLightbox({ src: a.url, name: a.name || 'Photo', download: a.download_url, onShowInChat });
        }
    }

    async showInChat(messageId) {
        const conversationId = this.conversationId;
        this.close();
        if (this.chat.active?.id !== conversationId) {
            await this.chat.openConversation(conversationId);
            for (let i = 0; i < 50 && !this.chat.active?.loaded; i++) await new Promise((resolve) => setTimeout(resolve, 100));
        }
        if (this.chat.active?.id === conversationId) this.chat.jumpToMessage(messageId, { deep: true });
    }
}
