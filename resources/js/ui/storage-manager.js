import axios from '../bootstrap';
import { errorMessage, formatBytes, formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { avatar } from '../chat/templates';

export const KINDS = [
    { key: 'photos', label: 'Photos', icon: 'image' },
    { key: 'videos', label: 'Videos', icon: 'film' },
    { key: 'documents', label: 'Documents', icon: 'file-text' },
    { key: 'voice', label: 'Voice messages', icon: 'mic' },
    { key: 'gifs', label: 'GIFs and stickers', icon: 'sticker' },
];

const count = (files) => `${files.toLocaleString()} ${files === 1 ? 'file' : 'files'}`;
const shortDate = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });

/**
 * D6 — Manage storage: space used by the files in my chats, the biggest files and chats,
 * and "delete for me" to clear them.
 */
export class StorageManager {
    constructor(container, routes) {
        this.container = container;
        this.routes = routes;
        this.body = container.querySelector('[data-storage-body]');
        this.summary = null;
        this.loading = false;
        this.panel = null;

        container.addEventListener('click', (event) => {
            if (event.target.closest('[data-storage-retry]')) this.load();
            const large = event.target.closest('[data-storage-large]');
            if (large) this.openFiles({ large: true, title: 'Larger than 5 MB' });
            const chat = event.target.closest('[data-storage-chat]');
            if (chat) {
                const card = this.summary?.chats.find((item) => String(item.id) === chat.dataset.storageChat);
                this.openFiles({ conversation: Number(chat.dataset.storageChat), title: card?.name ?? 'Chat', chat: card });
            }
        });
    }

    async load() {
        if (this.loading) return;
        this.loading = true;
        if (!this.summary) this.body.innerHTML = '<div class="storage-loading"><span class="spinner"></span></div>';

        try {
            const { data } = await axios.get(this.routes.storageSummary);
            this.summary = data;
            this.render();
        } catch (error) {
            this.body.innerHTML = html`<div class="storage-error"><p>${errorMessage(error, "Couldn't work out your storage.")}</p><button type="button" class="btn btn-secondary btn-sm" data-storage-retry>Try again</button></div>`;
        } finally {
            this.loading = false;
        }
    }

    render() {
        const { total, kinds, large, chats } = this.summary;

        if (!total.files) {
            this.body.innerHTML = html`<div class="storage-empty">${raw(icon('hard-drive'))}<p>No photos, videos or files in your chats yet.</p></div>`;
            return;
        }

        const segments = KINDS.filter((kind) => kinds[kind.key]?.bytes > 0)
            .map((kind) => html`<span class="storage-bar-part" data-kind="${kind.key}" style="flex-grow: ${kinds[kind.key].bytes}" title="${kind.label}: ${formatBytes(kinds[kind.key].bytes)}"></span>`)
            .join('');

        this.body.innerHTML = html`
            <div class="storage-usage">
                <p class="storage-total"><strong>${formatBytes(total.bytes)}</strong> <span>in ${count(total.files)}</span></p>
                <div class="storage-bar" role="img" aria-label="${KINDS.map((kind) => `${kind.label} ${formatBytes(kinds[kind.key]?.bytes ?? 0)}`).join(', ')}">${raw(segments)}</div>
                <ul class="storage-legend">
                    ${raw(KINDS.map((kind) => html`<li data-kind="${kind.key}"><span class="storage-dot"></span>${kind.label}<span class="storage-legend-size">${formatBytes(kinds[kind.key]?.bytes ?? 0)}</span></li>`).join(''))}
                </ul>
            </div>
            <button type="button" class="wa-row is-link storage-row" data-storage-large ${raw(large.files ? '' : 'disabled')}>
                <span class="storage-row-icon is-large">${raw(icon('file-archive'))}</span>
                <span class="wa-row-body">
                    <span class="wa-row-title">Larger than 5 MB</span>
                    <span class="wa-row-text">${large.files ? `${count(large.files)} · ${formatBytes(large.bytes)}` : 'No large files'}</span>
                </span>
                ${raw(large.files ? icon('chevron-right', 'wa-row-chevron') : '')}
            </button>
            <h4 class="storage-subtitle">Chats</h4>
            <div class="storage-chats">
                ${raw(chats.map((chat) => html`
                    <button type="button" class="wa-row is-link storage-row" data-storage-chat="${chat.id}">
                        ${raw(avatar(chat, 'sm'))}
                        <span class="wa-row-body">
                            <span class="wa-row-title">${chat.name}</span>
                            <span class="wa-row-text">${count(chat.files)}</span>
                        </span>
                        <span class="storage-size">${formatBytes(chat.bytes)}</span>
                    </button>`).join(''))}
            </div>
        `;
    }

    /* ------------------------------------------------------------------ */
    /* Files of a chat, or large files                                     */
    /* ------------------------------------------------------------------ */

    openFiles({ conversation = null, large = false, title, chat = null }) {
        this.closeFiles();
        this.files = { conversation, large, title, chat, sort: 'size', page: 0, items: [], hasMore: false, loading: false, selected: new Set() };

        const overlay = document.createElement('div');
        overlay.className = 'group-info storage-panel';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', title);
        overlay.innerHTML = html`
            <div class="group-info-backdrop" data-files-close></div>
            <aside class="group-info-panel">
                <header class="group-info-header">
                    <button type="button" class="btn-icon" data-files-close aria-label="Close">${raw(icon('arrow-left'))}</button>
                    <span class="gallery-heading">
                        <span class="group-info-title">${title}</span>
                        <span class="gallery-chat-name" data-files-subtitle></span>
                    </span>
                </header>
                <div class="storage-toolbar">
                    <div class="storage-sort" role="group" aria-label="Sort">
                        <button type="button" class="gallery-tab is-active" data-files-sort="size" aria-pressed="true">Largest</button>
                        <button type="button" class="gallery-tab" data-files-sort="newest" aria-pressed="false">Newest</button>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" data-files-select-all>Select all</button>
                </div>
                <div class="group-info-scroll gallery-scroll" data-files-scroll>
                    <div class="storage-files" data-files-list></div>
                </div>
                <footer class="storage-footer" data-files-footer hidden>
                    <span data-files-selection></span>
                    <button type="button" class="btn btn-danger btn-sm" data-files-delete>${raw(icon('trash-2'))} Delete for me</button>
                </footer>
            </aside>
        `;
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onFilesClick(event));
        overlay.addEventListener('change', (event) => {
            const box = event.target.closest('[data-file-select]');
            if (!box) return;
            if (box.checked) this.files.selected.add(Number(box.value));
            else this.files.selected.delete(Number(box.value));
            this.renderSelection();
        });
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) this.closeFiles();
        };
        document.addEventListener('keydown', this.onKey);
        overlay.querySelector('.btn-icon[data-files-close]')?.focus();

        this.loadFiles(true);
    }

    closeFiles() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.previousFocus?.focus?.();
    }

    async loadFiles(reset = false) {
        const state = this.files;
        if (!state || state.loading) return;
        state.loading = true;
        if (reset) {
            state.page = 0;
            state.items = [];
            state.selected.clear();
        }
        this.renderFiles();

        try {
            const params = { sort: state.sort, page: state.page + 1, ...(state.large ? { large: 1 } : {}), ...(state.conversation ? { conversation: state.conversation } : {}) };
            const { data } = await axios.get(this.routes.storageFiles, { params });
            if (state !== this.files) return;
            state.items = [...state.items, ...data.data];
            state.hasMore = Boolean(data.has_more);
            state.page += 1;
            state.error = null;
        } catch (error) {
            state.error = errorMessage(error, "Couldn't load the files.");
        } finally {
            state.loading = false;
            if (state === this.files) this.renderFiles();
        }
    }

    renderFiles() {
        const state = this.files;
        const list = this.panel?.querySelector('[data-files-list]');
        if (!list) return;

        const bytes = state.items.reduce((sum, item) => sum + (item.size || 0), 0);
        this.panel.querySelector('[data-files-subtitle]').textContent = state.items.length ? `${count(state.items.length)}${state.hasMore ? '+' : ''} · ${formatBytes(bytes)}${state.hasMore ? '+' : ''}` : '';

        if (!state.items.length) {
            list.innerHTML = state.loading
                ? '<div class="storage-loading"><span class="spinner"></span></div>'
                : state.error
                  ? html`<div class="storage-error"><p>${state.error}</p><button type="button" class="btn btn-secondary btn-sm" data-files-more>Try again</button></div>`
                  : html`<div class="storage-empty">${raw(icon('circle-check'))}<p>Nothing here.</p></div>`;
            this.renderSelection();
            return;
        }

        list.innerHTML = state.items.map((item) => this.fileRow(item)).join('') + (
            state.loading
                ? '<div class="storage-loading"><span class="spinner"></span></div>'
                : state.hasMore || state.error
                  ? html`<div class="gallery-retry"><button type="button" class="btn btn-ghost" data-files-more>${state.error ? 'Try again' : 'Load more'}</button></div>`
                  : ''
        );
        this.renderSelection();
    }

    fileRow(item) {
        const kind = KINDS.find((k) => k.key === item.kind) ?? KINDS[2];
        const thumb = item.thumbnail_url
            ? html`<img src="${item.thumbnail_url}" alt="" loading="lazy" decoding="async">`
            : icon(kind.icon);
        const details = [
            formatBytes(item.size),
            item.duration ? formatDuration(item.duration) : null,
            item.created_at ? shortDate.format(new Date(item.created_at)) : null,
            item.chat ? item.chat.name : null,
            item.is_mine ? 'Sent by you' : null,
        ].filter(Boolean).join(' · ');

        return html`
            <label class="storage-file">
                <input type="checkbox" data-file-select value="${item.id}" ${raw(this.files.selected.has(item.id) ? 'checked' : '')}>
                <span class="storage-file-thumb" data-kind="${item.kind}">${raw(thumb)}</span>
                <span class="storage-file-body">
                    <span class="storage-file-name">${item.name}</span>
                    <span class="storage-file-meta">${details}</span>
                </span>
                <a class="btn-icon btn-icon-sm" href="${item.download_url}" download="${item.name}" aria-label="Download ${item.name}" title="Download">${raw(icon('download'))}</a>
            </label>
        `;
    }

    renderSelection() {
        if (!this.panel) return;
        const { selected, items } = this.files;
        const footer = this.panel.querySelector('[data-files-footer]');
        footer.hidden = selected.size === 0;
        const bytes = items.filter((item) => selected.has(item.id)).reduce((sum, item) => sum + (item.size || 0), 0);
        this.panel.querySelector('[data-files-selection]').textContent = `${selected.size} selected · ${formatBytes(bytes)}`;
        const all = this.panel.querySelector('[data-files-select-all]');
        all.textContent = items.length && selected.size === items.length ? 'Clear selection' : 'Select all';
        all.disabled = !items.length;
    }

    async onFilesClick(event) {
        const target = event.target;
        if (target.closest('[data-files-close]')) return this.closeFiles();
        if (target.closest('[data-files-more]')) return this.loadFiles();

        const sort = target.closest('[data-files-sort]');
        if (sort && sort.dataset.filesSort !== this.files.sort) {
            this.files.sort = sort.dataset.filesSort;
            this.panel.querySelectorAll('[data-files-sort]').forEach((button) => {
                const active = button === sort;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', String(active));
            });
            return this.loadFiles(true);
        }

        if (target.closest('[data-files-select-all]')) {
            const { selected, items } = this.files;
            if (selected.size === items.length) selected.clear();
            else items.forEach((item) => selected.add(item.id));
            this.panel.querySelectorAll('[data-file-select]').forEach((box) => { box.checked = selected.has(Number(box.value)); });
            return this.renderSelection();
        }

        if (target.closest('[data-files-delete]')) return this.deleteSelected();
        return null;
    }

    async deleteSelected() {
        const state = this.files;
        const ids = [...state.selected];
        if (!ids.length) return;
        const bytes = state.items.filter((item) => state.selected.has(item.id)).reduce((sum, item) => sum + (item.size || 0), 0);

        const choice = await confirmDialog({
            title: ids.length === 1 ? 'Delete 1 file?' : `Delete ${ids.length} files?`,
            message: `${formatBytes(bytes)} will be removed from your chats on all your devices. Other people in the chat keep their copy.`,
            icon: 'trash-2',
            actions: [{ label: 'Delete for me', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;

        try {
            const chunks = [];
            for (let i = 0; i < ids.length; i += 200) chunks.push(ids.slice(i, i + 200));
            let deleted = 0;
            let freed = 0;
            for (const chunk of chunks) {
                const { data } = await axios.post(this.routes.storageDelete, { message_ids: chunk });
                deleted += data.deleted;
                freed += data.bytes;
            }
            toast.success(deleted === 1 ? `1 file deleted (${formatBytes(freed)}).` : `${deleted} files deleted (${formatBytes(freed)}).`);
            state.items = state.items.filter((item) => !state.selected.has(item.id));
            state.selected.clear();
            this.renderFiles();
            this.load();
        } catch (error) {
            toast.error(errorMessage(error, 'Could not delete the files.'));
        }
    }
}

/** Start when Storage and data is shown (not on every settings visit). */
export function initStorageManager(config, container = document.querySelector('[data-storage-manager]')) {
    if (!container || !config?.routes?.storageSummary) return null;
    const manager = new StorageManager(container, config.routes);
    const section = container.closest('[data-settings-section]');
    const start = () => {
        if (!manager.summary && !manager.loading) manager.load();
    };

    if (!section || section.classList.contains('is-active')) start();
    document.addEventListener('settings:section', (event) => {
        if (event.detail?.name === section?.dataset.settingsSection) start();
    });
    return manager;
}
