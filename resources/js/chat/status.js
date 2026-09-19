import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { promoteLink } from './ads';
import { formatDateTime } from './format';
import { callablePeople, pickPeople } from './people-picker';
import * as T from './templates';
import { videoDetails } from './video';

/**
 * Phase 5 — Status: 24-hour text, photo and video updates.
 * S1 post and watch, S2 who saw it, S3 privacy, S4 reply and react, S5 mute.
 */

export const BACKGROUNDS = ['teal', 'indigo', 'violet', 'rose', 'amber', 'emerald', 'sky', 'slate'];
export const FONT_COUNT = 5;
export const PHOTO_MS = 5000;
export const STATUS_REACTIONS = ['😍', '😂', '😮', '😢', '👏', '🔥'];

const PRIVACY_LABELS = {
    contacts: 'My contacts',
    except: 'My contacts except…',
    only: 'Only share with…',
};

/** "Just now", "12 minutes ago", then "Today, 9:41 AM". */
export function statusTime(value, now = Date.now()) {
    const minutes = Math.floor(Math.max(0, now - Date.parse(value)) / 60_000);
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return minutes === 1 ? '1 minute ago' : `${minutes} minutes ago`;
    return formatDateTime(value);
}

/** Ring around an avatar: one segment per update, seen ones greyed out. */
export function statusRing(statuses, { size = 48 } = {}) {
    const count = Math.max(1, statuses.length);
    const r = 21;
    const circumference = 2 * Math.PI * r;
    const gap = count > 1 ? Math.min(4, circumference / count / 3) : 0;
    const part = circumference / count;

    const segments = Array.from({ length: count }, (_, index) => {
        const viewed = statuses[index]?.viewed;
        return `<circle cx="22" cy="22" r="${r}" class="${viewed ? 'is-viewed' : ''}" stroke-dasharray="${(part - gap).toFixed(2)} ${(circumference - part + gap).toFixed(2)}" stroke-dashoffset="${(-index * part).toFixed(2)}" transform="rotate(-90 22 22)"/>`;
    }).join('');

    return `<svg class="status-ring-svg" viewBox="0 0 44 44" width="${size}" height="${size}" aria-hidden="true">${segments}</svg>`;
}

/** Index of the first update not seen yet (or 0 when all were seen). */
export function firstUnseen(statuses) {
    const index = statuses.findIndex((status) => !status.viewed);
    return index === -1 ? 0 : index;
}

/** The feed split into the three lists of the Status view. */
export function statusSections(updates) {
    return {
        recent: updates.filter((entry) => !entry.muted && !entry.viewed),
        viewed: updates.filter((entry) => !entry.muted && entry.viewed),
        muted: updates.filter((entry) => entry.muted),
    };
}

export class Statuses {
    constructor(chat) {
        this.chat = chat;
        this.feed = { mine: [], privacy: 'contacts', updates: [] };
        this.viewer = null;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="status"]'),
            body: q('[data-status-body]'),
        };

        if (!this.el.view || !chat.api.has('statuses')) {
            document.querySelectorAll('[data-action="open-status"], [data-mobile-tab="status"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            const { action } = event.detail;
            if (action === 'open-status') this.open();
            if (action === 'close-status') this.close();
            if (action === 'status-privacy') this.privacyDialog();
        });
        this.el.body.addEventListener('click', (event) => this.onListClick(event));

        this.fileInput = Object.assign(document.createElement('input'), { type: 'file', accept: 'image/jpeg,image/png,video/*', hidden: true });
        this.fileInput.addEventListener('change', () => {
            const file = this.fileInput.files?.[0];
            this.fileInput.value = '';
            if (file) this.mediaComposer(file);
        });
        document.body.appendChild(this.fileInput);

        document.addEventListener('chat:sidebar-mode', (event) => {
            if (event.detail.mode !== 'status' && !this.el.view.hidden) this.close({ silent: true });
        });
        document.addEventListener('app:back', (event) => {
            if (this.viewer) {
                event.preventDefault();
                this.closeViewer();
            } else if (this.isOpen && !this.chat.active) {
                event.preventDefault();
                this.close();
            }
        });

        // A dot on the Status button when there is something new to watch.
        this.loadTimer = setTimeout(() => this.load(), 1200);
    }

    get isOpen() {
        return this.el.sidebar?.dataset.mode === 'status';
    }

    get me() {
        return this.chat.me;
    }

    /* ------------------------------------------------------------------ */
    /* Sidebar view                                                        */
    /* ------------------------------------------------------------------ */

    async open() {
        if (this.chat.contactsPanel?.isOpen) this.chat.contactsPanel.close();
        if (this.chat.callLog?.isOpen) this.chat.callLog.close({ silent: true });
        if (this.chat.starred?.isOpen) this.chat.starred.close({ silent: true });
        if (this.chat.communities?.isOpen) this.chat.communities.close({ silent: true });
        if (this.chat.channels?.isOpen) this.chat.channels.close({ silent: true });
        this.el.sidebar.dataset.mode = 'status';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView?.();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'status' } }));

        this.render();
        await this.load();
    }

    close({ silent = false } = {}) {
        this.el.view.hidden = true;
        if (silent) return;
        this.el.chatsView.hidden = false;
        if (this.isOpen) this.el.sidebar.dataset.mode = 'chats';
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    async load() {
        clearTimeout(this.loadTimer);
        try {
            this.feed = await this.chat.api.statuses();
            this.loaded = true;
            this.error = null;
        } catch (error) {
            this.error = errorMessage(error, "Couldn't load status updates.");
        }
        this.render();
        this.updateDot();
    }

    updateDot() {
        const fresh = (this.feed.updates ?? []).some((entry) => !entry.muted && !entry.viewed);
        document.querySelectorAll('[data-status-dot]').forEach((dot) => {
            dot.hidden = !fresh;
        });
    }

    nameOf(user) {
        return this.chat.displayName?.(user.id, user.saved_name || user.name) || user.saved_name || user.name;
    }

    render() {
        if (!this.el.body || !this.isOpen) return;
        const mine = this.feed.mine ?? [];
        const { recent, viewed, muted } = statusSections(this.feed.updates ?? []);
        const latestMine = mine.at(-1);

        const personRow = (entry) => html`
            <button type="button" class="status-row" data-status-user="${entry.user.id}">
                <span class="status-ring">${raw(statusRing(entry.statuses))}${raw(T.avatar(entry.user, 'md'))}</span>
                <span class="status-row-body">
                    <span class="status-row-name">${this.nameOf(entry.user)}</span>
                    <span class="status-row-meta">${statusTime(entry.latest_at)}</span>
                </span>
            </button>`;

        this.el.body.innerHTML = html`
            <div class="status-mine">
                <button type="button" class="status-row" ${raw(mine.length ? 'data-status-mine' : 'data-status-new-text')}>
                    <span class="status-ring${mine.length ? '' : ' is-empty'}">
                        ${raw(mine.length ? statusRing(mine.map((status) => ({ ...status, viewed: false }))) : '')}
                        ${raw(T.avatar(this.me, 'md'))}
                        ${raw(mine.length ? '' : `<span class="status-add-badge">${icon('plus')}</span>`)}
                    </span>
                    <span class="status-row-body">
                        <span class="status-row-name">My status</span>
                        <span class="status-row-meta">${mine.length ? `${mine.length === 1 ? '1 update' : `${mine.length} updates`} · ${statusTime(latestMine.created_at)}` : 'Tap to add a status update'}</span>
                    </span>
                </button>
                <button type="button" class="btn-icon" data-status-new-text aria-label="New text status" title="Text">${raw(icon('pencil'))}</button>
                <button type="button" class="btn-icon" data-status-new-media aria-label="New photo or video status" title="Photo or video">${raw(icon('camera'))}</button>
            </div>
            ${raw(this.error ? html`<p class="receipt-empty status-empty">${this.error}</p>` : '')}
            ${raw(recent.length ? html`<div class="sidebar-section-title">Recent updates</div>${raw(recent.map(personRow).join(''))}` : '')}
            ${raw(viewed.length ? html`<div class="sidebar-section-title">Viewed updates</div>${raw(viewed.map(personRow).join(''))}` : '')}
            ${raw(muted.length ? html`<details class="status-muted"${raw(this.mutedOpen ? ' open' : '')}><summary class="sidebar-section-title">Muted updates (${muted.length})</summary>${raw(muted.map(personRow).join(''))}</details>` : '')}
            ${raw(this.loaded && !this.error && !(this.feed.updates ?? []).length ? '<p class="receipt-empty status-empty">No updates from your contacts right now. Updates disappear after 24 hours.</p>' : '')}
            ${raw(this.chat.api.has('channels') ? html`
                <div class="sidebar-section-title status-channels-title">Channels</div>
                <button type="button" class="status-row" data-status-channels>
                    <span class="avatar avatar-md"><span class="avatar-fallback is-accent">${raw(icon('rss'))}</span></span>
                    <span class="status-row-body">
                        <span class="status-row-name">Find channels</span>
                        <span class="status-row-meta">Follow updates from people and topics you care about</span>
                    </span>
                    ${raw(icon('chevron-right'))}
                </button>` : '')}
            <button type="button" class="status-privacy-row" data-status-privacy>
                ${raw(icon('lock', 'icon-xs'))}
                <span>Status privacy: <strong>${PRIVACY_LABELS[this.feed.privacy] ?? PRIVACY_LABELS.contacts}</strong></span>
            </button>
        `;
        this.el.body.querySelector('.status-muted')?.addEventListener('toggle', (event) => {
            this.mutedOpen = event.target.open;
        });
    }

    onListClick(event) {
        const t = event.target;
        if (t.closest('[data-status-new-text]')) return this.textComposer();
        if (t.closest('[data-status-new-media]')) return this.fileInput.click();
        if (t.closest('[data-status-privacy]')) return this.privacyDialog();
        if (t.closest('[data-status-channels]')) return this.chat.channels?.open();
        if (t.closest('[data-status-mine]')) return this.openMine();
        const person = t.closest('[data-status-user]');
        if (person) return this.openUser(Number(person.dataset.statusUser));
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Viewer                                                              */
    /* ------------------------------------------------------------------ */

    openMine(index = 0) {
        const mine = this.feed.mine ?? [];
        if (!mine.length) return;
        this.openViewer([{ user: this.me, statuses: mine, mine: true }], 0, index);
    }

    openUser(userId) {
        const { recent, viewed, muted } = statusSections(this.feed.updates ?? []);
        const entry = (this.feed.updates ?? []).find((item) => Number(item.user.id) === Number(userId));
        if (!entry) return;
        // Carry on with the other people in the same list, like WhatsApp.
        const list = [recent, viewed, muted].find((part) => part.includes(entry));
        this.openViewer(list, list.indexOf(entry), firstUnseen(entry.statuses));
    }

    openViewer(people, personIndex, statusIndex) {
        this.closeViewer({ silent: true });
        const overlay = document.createElement('div');
        overlay.className = 'status-viewer';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Status');
        document.body.appendChild(overlay);

        this.previousFocus = document.activeElement;
        this.viewer = { overlay, people, personIndex, statusIndex, paused: false, holding: false, elapsed: 0, duration: PHOTO_MS, ready: false };

        overlay.addEventListener('click', (event) => this.onViewerClick(event));
        overlay.addEventListener('submit', (event) => this.onReply(event));
        overlay.addEventListener('focusin', (event) => {
            if (event.target.matches('[data-viewer-input]')) this.viewer && (this.viewer.typing = true);
        });
        overlay.addEventListener('focusout', (event) => {
            if (event.target.matches('[data-viewer-input]')) this.viewer && (this.viewer.typing = false);
        });
        // Press and hold to pause.
        overlay.addEventListener('pointerdown', (event) => {
            if (!this.viewer || !event.target.closest('[data-viewer-stage]') || event.target.closest('button, input, form')) return;
            this.holdTimer = setTimeout(() => this.viewer && (this.viewer.holding = true), 180);
        });
        const release = () => {
            clearTimeout(this.holdTimer);
            if (this.viewer?.holding) {
                this.viewer.holding = false;
                this.viewer.justHeld = true;
                setTimeout(() => this.viewer && (this.viewer.justHeld = false), 50);
            }
        };
        overlay.addEventListener('pointerup', release);
        overlay.addEventListener('pointercancel', release);

        this.onKey = (event) => {
            if (!this.viewer || document.querySelector('.modal')) return;
            if (event.key === 'Escape') {
                if (this.viewer.sheet) this.closeViewers();
                else this.closeViewer();
            }
            if (this.viewer?.typing) return;
            if (event.key === 'ArrowRight') this.next();
            if (event.key === 'ArrowLeft') this.prev();
            if (event.key === ' ') {
                event.preventDefault();
                this.togglePause();
            }
        };
        document.addEventListener('keydown', this.onKey);

        this.show();
        this.tick = this.tick.bind(this);
        this.lastFrame = performance.now();
        this.frame = requestAnimationFrame(this.tick);
    }

    current() {
        const v = this.viewer;
        if (!v) return null;
        const person = v.people[v.personIndex];
        return person ? { person, status: person.statuses[v.statusIndex] } : null;
    }

    show() {
        const v = this.viewer;
        const now = this.current();
        if (!v || !now?.status) return this.closeViewer();
        const { person, status } = now;
        v.elapsed = 0;
        v.duration = PHOTO_MS;
        v.ready = status.type === 'text';
        v.video = null;

        const mine = Boolean(person.mine);
        const name = mine ? 'My status' : this.nameOf(person.user);
        const caption = status.type !== 'text' && status.text ? html`<p class="status-caption">${status.text}</p>` : '';
        const content = status.type === 'text'
            ? html`<div class="status-text is-bg-${status.background || 'teal'} is-font-${Number(status.font) || 0}"><p>${status.text}</p></div>`
            : status.type === 'video'
              ? html`<video class="status-media" src="${status.media_url}" ${raw(status.thumbnail_url ? html`poster="${status.thumbnail_url}"` : '')} playsinline autoplay preload="auto"${raw(this.muted ? ' muted' : '')}></video>${raw(caption)}`
              : html`<img class="status-media" src="${status.media_url}" alt="">${raw(caption)}`;

        v.overlay.innerHTML = html`
            <div class="status-stage" data-viewer-stage>
                <div class="status-progress">${raw(person.statuses.map((_, index) => html`<span class="status-progress-bar"><span style="width: ${index < v.statusIndex ? 100 : 0}%" data-progress="${index}"></span></span>`).join(''))}</div>
                <header class="status-viewer-header">
                    <button type="button" class="btn-icon" data-viewer-close aria-label="Close status">${raw(icon('arrow-left'))}</button>
                    ${raw(T.avatar(person.user, 'sm'))}
                    <span class="status-viewer-title">
                        <span class="status-viewer-name">${name}</span>
                        <span class="status-viewer-time">${statusTime(status.created_at)}</span>
                    </span>
                    <button type="button" class="btn-icon" data-viewer-pause aria-label="Pause">${raw(icon('pause'))}</button>
                    ${raw(status.type === 'video' ? html`<button type="button" class="btn-icon" data-viewer-sound aria-label="${this.muted ? 'Sound on' : 'Mute'}">${raw(icon(this.muted ? 'volume-x' : 'volume-2'))}</button>` : '')}
                    ${raw(mine && promoteLink('status', status.id)
                        ? html`<a class="btn-icon" href="${promoteLink('status', status.id)}" data-viewer-promote aria-label="Promote status" title="Promote">${raw(icon('megaphone'))}</a>`
                        : '')}
                    ${raw(mine
                        ? html`<button type="button" class="btn-icon" data-viewer-delete aria-label="Delete status" title="Delete">${raw(icon('trash-2'))}</button>`
                        : html`<button type="button" class="btn-icon" data-viewer-mute aria-label="${person.muted ? 'Unmute' : 'Mute'} ${name}" title="${person.muted ? 'Unmute' : 'Mute'}">${raw(icon(person.muted ? 'bell' : 'bell-off'))}</button>`)}
                </header>
                <div class="status-content">${raw(content)}</div>
                <button type="button" class="status-nav is-prev" data-viewer-prev aria-label="Previous update"></button>
                <button type="button" class="status-nav is-next" data-viewer-next aria-label="Next update"></button>
                <footer class="status-viewer-footer">
                    ${raw(mine
                        ? html`<button type="button" class="status-views-button" data-viewer-viewers>${raw(icon('eye'))} <span data-viewer-count>${status.views_count ?? 0}</span> ${Number(status.views_count) === 1 ? 'view' : 'views'}</button>`
                        : html`
                            <div class="status-reactions" role="group" aria-label="React">${raw(STATUS_REACTIONS.map((emoji) => html`<button type="button" class="status-reaction" data-viewer-react="${emoji}" aria-label="React ${emoji}">${emoji}</button>`).join(''))}</div>
                            <form class="status-reply" data-viewer-reply>
                                <input class="status-reply-input" data-viewer-input maxlength="2000" placeholder="Reply to ${name}…" aria-label="Reply" autocomplete="off">
                                <button type="submit" class="status-reply-send" aria-label="Send reply">${raw(icon('send-horizontal'))}</button>
                            </form>`)}
                </footer>
            </div>
        `;

        const media = v.overlay.querySelector('.status-media');
        if (status.type === 'image' && media) {
            const ready = () => this.viewer && (this.viewer.ready = true);
            if (media.complete) ready();
            else {
                media.addEventListener('load', ready, { once: true });
                media.addEventListener('error', ready, { once: true });
            }
        } else if (status.type === 'video' && media) {
            v.video = media;
            media.addEventListener('loadedmetadata', () => {
                if (!this.viewer) return;
                this.viewer.duration = Math.max(1000, (Number.isFinite(media.duration) ? media.duration : status.duration || 5) * 1000);
                this.viewer.ready = true;
            }, { once: true });
            media.addEventListener('ended', () => this.next(), { once: true });
            media.addEventListener('error', () => this.viewer && (this.viewer.ready = true), { once: true });
            media.play?.()?.catch?.(() => {
                // Autoplay with sound was refused: play muted instead.
                this.muted = true;
                media.muted = true;
                media.play?.()?.catch?.(() => {});
            });
        }

        // S2: tell the owner I saw it.
        if (!mine && !status.viewed) {
            status.viewed = true;
            this.chat.api.viewStatus(status.id).catch(() => {});
            person.viewed = person.statuses.every((item) => item.viewed);
        }
        return null;
    }

    tick(time) {
        const v = this.viewer;
        if (!v) return;
        const delta = Math.min(100, time - this.lastFrame);
        this.lastFrame = time;
        const stopped = v.paused || v.holding || v.typing || v.sheet || !v.ready || document.hidden || document.querySelector('.modal');

        if (v.video) {
            if (stopped && !v.video.paused) v.video.pause();
            if (!stopped && v.video.paused && v.ready && !v.video.ended) v.video.play?.()?.catch?.(() => {});
            v.elapsed = (v.video.currentTime || 0) * 1000;
        } else if (!stopped) {
            v.elapsed += delta;
        }

        const bar = v.overlay.querySelector(`[data-progress="${v.statusIndex}"]`);
        if (bar) bar.style.width = `${Math.min(100, (v.elapsed / v.duration) * 100)}%`;

        if (!v.video && v.elapsed >= v.duration) this.next();
        this.frame = requestAnimationFrame(this.tick);
    }

    next() {
        const v = this.viewer;
        if (!v) return;
        const person = v.people[v.personIndex];
        if (v.statusIndex < person.statuses.length - 1) {
            v.statusIndex++;
        } else if (v.personIndex < v.people.length - 1) {
            v.personIndex++;
            v.statusIndex = firstUnseen(v.people[v.personIndex].statuses);
        } else {
            this.closeViewer();
            return;
        }
        this.show();
    }

    prev() {
        const v = this.viewer;
        if (!v) return;
        if (v.statusIndex > 0) {
            v.statusIndex--;
        } else if (v.personIndex > 0) {
            v.personIndex--;
            v.statusIndex = v.people[v.personIndex].statuses.length - 1;
        }
        this.show();
    }

    togglePause() {
        if (!this.viewer) return;
        this.viewer.paused = !this.viewer.paused;
        const button = this.viewer.overlay.querySelector('[data-viewer-pause]');
        if (button) {
            button.innerHTML = icon(this.viewer.paused ? 'play' : 'pause');
            button.setAttribute('aria-label', this.viewer.paused ? 'Play' : 'Pause');
        }
    }

    closeViewer({ silent = false } = {}) {
        if (!this.viewer) return;
        cancelAnimationFrame(this.frame);
        document.removeEventListener('keydown', this.onKey);
        this.viewer.video?.pause?.();
        this.viewer.overlay.remove();
        this.viewer = null;
        if (silent) return;
        this.previousFocus?.focus?.();
        this.recomputeViewed();
        this.render();
        this.updateDot();
        if (this.reloadAfterViewer) {
            this.reloadAfterViewer = false;
            this.load();
        }
    }

    recomputeViewed() {
        for (const entry of this.feed.updates ?? []) {
            entry.viewed = entry.statuses.every((status) => status.viewed);
        }
    }

    onViewerClick(event) {
        const t = event.target;
        const now = this.current();
        if (!now) return null;
        if (t.closest('[data-viewer-close]')) return this.closeViewer();
        if (t.closest('[data-viewer-viewers-close]')) return this.closeViewers();
        if (t.closest('[data-viewer-sheet]')) return null;
        if (this.viewer.justHeld) return null;
        if (t.closest('[data-viewer-next]')) return this.next();
        if (t.closest('[data-viewer-prev]')) return this.prev();
        if (t.closest('[data-viewer-pause]')) return this.togglePause();
        if (t.closest('[data-viewer-sound]')) {
            this.muted = !this.muted;
            if (this.viewer.video) this.viewer.video.muted = this.muted;
            const button = t.closest('[data-viewer-sound]');
            button.innerHTML = icon(this.muted ? 'volume-x' : 'volume-2');
            return null;
        }
        if (t.closest('[data-viewer-viewers]')) return this.openViewers(now.status);
        if (t.closest('[data-viewer-delete]')) return this.remove(now.status);
        if (t.closest('[data-viewer-mute]')) return this.toggleMute(now.person);
        const react = t.closest('[data-viewer-react]');
        if (react) return this.react(now.status, react.dataset.viewerReact, react);
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* S2 — Viewers                                                        */
    /* ------------------------------------------------------------------ */

    async openViewers(status) {
        const v = this.viewer;
        if (!v) return;
        v.sheet = true;
        const sheet = document.createElement('div');
        sheet.className = 'status-viewers-sheet';
        sheet.setAttribute('data-viewer-sheet', '');
        sheet.innerHTML = html`
            <header class="status-viewers-head">
                <span>Viewed by <strong data-viewers-total>${status.views_count ?? 0}</strong></span>
                <button type="button" class="btn-icon" data-viewer-viewers-close aria-label="Close">${raw(icon('x'))}</button>
            </header>
            <div class="status-viewers-list"><div class="flex justify-center p-4"><span class="spinner"></span></div></div>
        `;
        v.overlay.querySelector('[data-viewer-stage]').appendChild(sheet);
        sheet.querySelector('[data-viewer-viewers-close]').addEventListener('click', () => this.closeViewers());

        try {
            const { data } = await this.chat.api.statusViewers(status.id);
            if (!this.viewer?.sheet) return;
            sheet.querySelector('[data-viewers-total]').textContent = data.length;
            sheet.querySelector('.status-viewers-list').innerHTML = data.length
                ? data.map((row) => html`
                    <div class="status-viewer-row">
                        ${raw(T.avatar(row.user, 'sm'))}
                        <span class="status-row-body">
                            <span class="status-row-name">${this.nameOf(row.user)}</span>
                            <span class="status-row-meta">${statusTime(row.viewed_at)}</span>
                        </span>
                        ${raw(row.reaction ? html`<span class="status-viewer-reaction" title="Reaction">${row.reaction}</span>` : '')}
                    </div>`).join('')
                : '<p class="receipt-empty">No views yet.</p>';
        } catch (error) {
            sheet.querySelector('.status-viewers-list').innerHTML = html`<p class="receipt-empty">${errorMessage(error, "Couldn't load viewers.")}</p>`;
        }
    }

    closeViewers() {
        if (!this.viewer) return;
        this.viewer.overlay.querySelector('.status-viewers-sheet')?.remove();
        this.viewer.sheet = false;
    }

    /** Live: someone saw one of my updates. */
    onViewed({ status_id: statusId, views_count: count }) {
        const status = (this.feed.mine ?? []).find((item) => Number(item.id) === Number(statusId));
        if (!status) return;
        status.views_count = count;
        const now = this.current();
        if (now?.person.mine && Number(now.status.id) === Number(statusId)) {
            const counter = this.viewer.overlay.querySelector('[data-viewer-count]');
            if (counter) counter.textContent = count;
        }
        this.render();
    }

    /** Live: someone posted or deleted an update I may see (or I did, elsewhere). */
    onRemoteUpdate() {
        clearTimeout(this.remoteTimer);
        this.remoteTimer = setTimeout(() => {
            // While an update is open, reload once the viewer closes.
            if (this.viewer) this.reloadAfterViewer = true;
            else this.load();
        }, 800);
    }

    /* ------------------------------------------------------------------ */
    /* S1 — Delete                                                         */
    /* ------------------------------------------------------------------ */

    async remove(status) {
        this.viewer && (this.viewer.paused = true);
        const choice = await confirmDialog({
            title: 'Delete this status update?',
            message: 'It will be deleted for everyone who can see it.',
            icon: 'trash-2',
            actions: [{ label: 'Delete', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') {
            this.viewer && (this.viewer.paused = false);
            return;
        }

        try {
            await this.chat.api.deleteStatus(status.id);
            this.feed.mine = (this.feed.mine ?? []).filter((item) => item.id !== status.id);
            toast.success('Status update deleted.');
            const v = this.viewer;
            if (v) {
                const person = v.people[v.personIndex];
                person.statuses = this.feed.mine;
                v.paused = false;
                if (!person.statuses.length) this.closeViewer();
                else {
                    v.statusIndex = Math.min(v.statusIndex, person.statuses.length - 1);
                    this.show();
                }
            }
            this.render();
        } catch (error) {
            this.viewer && (this.viewer.paused = false);
            toast.error(errorMessage(error, "Couldn't delete the update."));
        }
    }

    /* ------------------------------------------------------------------ */
    /* S4 — Reply and react                                                */
    /* ------------------------------------------------------------------ */

    async onReply(event) {
        const form = event.target.closest('[data-viewer-reply]');
        if (!form) return;
        event.preventDefault();
        const now = this.current();
        const input = form.querySelector('[data-viewer-input]');
        const text = input.value.trim();
        if (!now || !text) return;

        form.querySelector('[type="submit"]').disabled = true;
        try {
            const message = await this.chat.api.replyStatus(now.status.id, text);
            input.value = '';
            input.blur();
            this.stored(message);
            toast.success('Reply sent.', { timeout: 1800 });
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't send the reply."));
        } finally {
            form.querySelector('[type="submit"]').disabled = false;
        }
    }

    async react(status, emoji, button) {
        button.classList.add('is-sent');
        setTimeout(() => button.classList.remove('is-sent'), 700);
        try {
            const data = await this.chat.api.reactStatus(status.id, emoji);
            if (data.message) this.stored(data.message);
            toast.success(`Reacted ${emoji}`, { timeout: 1500 });
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't send the reaction."));
        }
    }

    /** A reply or reaction went to the chat: show it in the chat list. */
    stored(message) {
        if (!message) return;
        const normalized = this.chat.normalizeMessage ? this.chat.normalizeMessage(message) : message;
        this.chat.onOwnMessageStored?.(normalized);
    }

    /* ------------------------------------------------------------------ */
    /* S5 — Mute                                                           */
    /* ------------------------------------------------------------------ */

    async toggleMute(person) {
        const muting = !person.muted;
        try {
            await (muting ? this.chat.api.muteStatus(person.user.id) : this.chat.api.unmuteStatus(person.user.id));
            const entry = (this.feed.updates ?? []).find((item) => Number(item.user.id) === Number(person.user.id));
            if (entry) entry.muted = muting;
            person.muted = muting;
            toast.success(muting ? `${this.nameOf(person.user)}'s updates are muted.` : `${this.nameOf(person.user)}'s updates are unmuted.`);
            this.show();
            this.render();
            this.updateDot();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't change mute."));
        }
    }

    /* ------------------------------------------------------------------ */
    /* S1 — Composers                                                      */
    /* ------------------------------------------------------------------ */

    textComposer() {
        const state = { background: BACKGROUNDS[Math.floor(Math.random() * BACKGROUNDS.length)], font: 0 };
        const overlay = document.createElement('div');
        overlay.className = 'status-composer';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'New text status');
        const paint = () => {
            overlay.className = `status-composer status-text is-bg-${state.background} is-font-${state.font}`;
        };
        overlay.innerHTML = html`
            <header class="status-composer-bar">
                <button type="button" class="btn-icon" data-composer-close aria-label="Close">${raw(icon('x'))}</button>
                <span class="flex-1"></span>
                <button type="button" class="btn-icon" data-composer-font aria-label="Change font" title="Font">${raw(icon('type'))}</button>
                <button type="button" class="btn-icon" data-composer-color aria-label="Change colour" title="Colour">${raw(icon('palette'))}</button>
            </header>
            <textarea class="status-composer-input" maxlength="700" placeholder="Type a status" aria-label="Status text" data-composer-text></textarea>
            <footer class="status-composer-footer">
                <span class="status-composer-privacy">${raw(icon('lock', 'icon-xs'))} ${PRIVACY_LABELS[this.feed.privacy] ?? PRIVACY_LABELS.contacts}</span>
                <button type="button" class="status-composer-send" data-composer-send aria-label="Post status" disabled>${raw(icon('send-horizontal'))}</button>
            </footer>
        `;
        paint();

        const text = overlay.querySelector('[data-composer-text]');
        const send = overlay.querySelector('[data-composer-send]');
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
        };
        const onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) close();
        };
        const fit = () => {
            const length = text.value.length;
            text.style.fontSize = length > 240 ? '1.35rem' : length > 120 ? '1.8rem' : '2.4rem';
            text.style.height = 'auto';
            text.style.height = `${Math.min(text.scrollHeight, window.innerHeight * 0.6)}px`;
            send.disabled = text.value.trim() === '';
        };

        text.addEventListener('input', fit);
        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-composer-close]')) return close();
            if (event.target.closest('[data-composer-color]')) {
                state.background = BACKGROUNDS[(BACKGROUNDS.indexOf(state.background) + 1) % BACKGROUNDS.length];
                paint();
            }
            if (event.target.closest('[data-composer-font]')) {
                state.font = (state.font + 1) % FONT_COUNT;
                paint();
            }
            if (event.target.closest('[data-composer-send]') && !send.disabled) {
                send.disabled = true;
                if (await this.post({ text: text.value, background: state.background, font: state.font })) close();
                else send.disabled = false;
            }
            return null;
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        fit();
        text.focus();
    }

    async mediaComposer(file) {
        const isVideo = file.type.startsWith('video/');
        const isImage = ['image/jpeg', 'image/png'].includes(file.type);
        if (!isVideo && !isImage) {
            toast.error('Choose a JPG or PNG photo, or a video.');
            return;
        }

        const max = Number(this.chat.config?.statuses?.maxVideoSeconds ?? 60);
        const details = isVideo ? await videoDetails(file) : null;
        if (details?.duration && details.duration > max + 0.5) {
            toast.error(`Status videos can be up to ${max} seconds long. Trim the video and try again.`);
            return;
        }

        const url = URL.createObjectURL(file);
        const overlay = document.createElement('div');
        overlay.className = 'status-composer is-media';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'New photo or video status');
        overlay.innerHTML = html`
            <header class="status-composer-bar">
                <button type="button" class="btn-icon" data-composer-close aria-label="Close">${raw(icon('x'))}</button>
            </header>
            <div class="status-composer-preview">
                ${raw(isVideo ? html`<video src="${url}" controls playsinline autoplay muted loop></video>` : html`<img src="${url}" alt="">`)}
            </div>
            <footer class="status-composer-footer is-caption">
                <input class="status-caption-input" maxlength="700" placeholder="Add a caption…" aria-label="Caption" data-composer-caption>
                <button type="button" class="status-composer-send" data-composer-send aria-label="Post status">${raw(icon('send-horizontal'))}</button>
            </footer>
        `;

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            URL.revokeObjectURL(url);
        };
        const onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) close();
        };
        const submit = async () => {
            const send = overlay.querySelector('[data-composer-send]');
            if (send.disabled) return;
            send.disabled = true;
            send.innerHTML = '<span class="spinner"></span>';
            const form = new FormData();
            form.append('attachment', file, file.name || (isVideo ? 'video.mp4' : 'photo.jpg'));
            form.append('caption', overlay.querySelector('[data-composer-caption]').value.trim());
            if (details?.thumbnail) form.append('thumbnail', details.thumbnail, 'poster.jpg');
            if (details?.duration) form.append('duration', String(Math.round(details.duration * 10) / 10));
            if (await this.post(form)) close();
            else {
                send.disabled = false;
                send.innerHTML = icon('send-horizontal');
            }
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-composer-close]')) close();
            if (event.target.closest('[data-composer-send]')) submit();
        });
        overlay.querySelector('[data-composer-caption]').addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submit();
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-composer-caption]').focus();
    }

    async post(payload) {
        try {
            const status = await this.chat.api.createStatus(payload);
            this.feed.mine = [...(this.feed.mine ?? []), status];
            toast.success('Status posted.');
            this.render();
            return true;
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't post the status."));
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* S3 — Privacy                                                        */
    /* ------------------------------------------------------------------ */

    async privacyDialog() {
        let privacy;
        try {
            privacy = await this.chat.api.statusPrivacy();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't load status privacy."));
            return;
        }

        const state = { mode: privacy.mode, except: privacy.except_ids ?? [], only: privacy.only_ids ?? [] };
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'status-privacy-title');

        const render = () => {
            const count = (ids, word) => (ids.length ? `${ids.length} ${word}` : 'Nobody chosen');
            overlay.innerHTML = html`
                <div class="modal-backdrop" data-privacy-cancel></div>
                <form class="modal-panel group-form status-privacy" novalidate>
                    <h2 class="modal-title" id="status-privacy-title">Status privacy</h2>
                    <p class="group-form-hint">Who can see my status updates. Changes don't affect updates you already shared.</p>
                    <label class="status-privacy-option">
                        <input type="radio" name="mode" value="contacts" ${raw(state.mode === 'contacts' ? 'checked' : '')}>
                        <span><strong>My contacts</strong><small>People you saved and people you chat with</small></span>
                    </label>
                    <label class="status-privacy-option">
                        <input type="radio" name="mode" value="except" ${raw(state.mode === 'except' ? 'checked' : '')}>
                        <span><strong>My contacts except…</strong><small>${count(state.except, 'excluded')}</small></span>
                        <button type="button" class="btn btn-secondary btn-sm" data-privacy-pick="except">Choose</button>
                    </label>
                    <label class="status-privacy-option">
                        <input type="radio" name="mode" value="only" ${raw(state.mode === 'only' ? 'checked' : '')}>
                        <span><strong>Only share with…</strong><small>${count(state.only, 'chosen')}</small></span>
                        <button type="button" class="btn btn-secondary btn-sm" data-privacy-pick="only">Choose</button>
                    </label>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-privacy-cancel>Cancel</button>
                        <button type="submit" class="btn btn-primary">Done</button>
                    </div>
                </form>
            `;
        };

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape' && overlay.isConnected && document.querySelectorAll('.modal').length === 1) close();
        };

        overlay.addEventListener('change', (event) => {
            if (event.target.name === 'mode') state.mode = event.target.value;
        });
        overlay.addEventListener('click', async (event) => {
            if (event.target.closest('[data-privacy-cancel]')) return close();
            const pick = event.target.closest('[data-privacy-pick]');
            if (!pick) return null;
            event.preventDefault();
            const list = pick.dataset.privacyPick;
            const choice = await pickPeople(this.chat, {
                title: list === 'except' ? 'Hide my status from…' : 'Share my status with…',
                max: 5000,
                submitLabel: 'Done',
                people: callablePeople(this.chat),
                selected: state[list],
            });
            if (choice) {
                state[list] = choice.ids;
                state.mode = list;
                render();
            }
            return null;
        });
        overlay.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (state.mode === 'only' && !state.only.length) {
                toast.info('Choose at least one person to share your status with.');
                return;
            }
            try {
                const saved = await this.chat.api.updateStatusPrivacy({ mode: state.mode, except_ids: state.except, only_ids: state.only });
                this.feed.privacy = saved.mode;
                close();
                toast.success(`Status privacy: ${PRIVACY_LABELS[saved.mode]}`);
                this.render();
            } catch (error) {
                toast.error(errorMessage(error, "Couldn't save status privacy."));
            }
        });

        render();
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input[name="mode"]:checked')?.focus();
    }
}
