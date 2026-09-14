import { debounce, errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { formatListTime } from './format';
import * as T from './templates';

/**
 * G11 — Channels: follow one-way updates, find channels, and run your own.
 * Followers never see each other, and updates come from the channel, not a person.
 */

/** 950 → "950", 1234 → "1.2K", 2500000 → "2.5M". */
export function compactCount(value) {
    const n = Math.max(0, Number(value) || 0);
    if (n < 1000) return String(n);
    const [size, unit] = n < 1_000_000 ? [1000, 'K'] : [1_000_000, 'M'];
    const short = n / size;
    return `${short < 10 ? Math.floor(short * 10) / 10 : Math.floor(short)}${unit}`;
}

export const followersLabel = (count) => (Number(count) === 1 ? '1 follower' : `${compactCount(count)} followers`);

/** The pseudo-user a channel is drawn with (avatar, name). */
export const channelAvatar = (id, channel) => ({
    id: `channel-${id}`,
    name: channel?.name ?? 'Channel',
    avatar_url: channel?.avatar_url ?? null,
    initials: channel?.initials ?? '#',
    avatar_hue: channel?.avatar_hue ?? 0,
    is_group: true,
    is_online: false,
    last_seen: null,
});

/**
 * Channel payloads list only the viewer among the people who reacted or voted, and
 * realtime updates list nobody: keep my own reaction and votes when such an update arrives.
 */
export function keepMyChoices(incoming, current, meId) {
    if (!current) return incoming;
    const me = Number(meId);
    const has = (ids) => (ids ?? []).some((id) => Number(id) === me);
    const next = { ...incoming };

    if (Array.isArray(incoming.reactions)) {
        const mine = (current.reactions ?? []).find((reaction) => has(reaction.user_ids))?.emoji;
        next.reactions = incoming.reactions.map((reaction) => (reaction.emoji === mine && reaction.count > 0 && !has(reaction.user_ids)
            ? { ...reaction, user_ids: [...reaction.user_ids, me] }
            : reaction));
    }

    if (incoming.poll?.options && current.poll?.options) {
        const chosen = new Set(current.poll.options.filter((option) => has(option.voter_ids)).map((option) => option.id));
        next.poll = {
            ...incoming.poll,
            options: incoming.poll.options.map((option) => (chosen.has(option.id) && option.count > 0 && !has(option.voter_ids)
                ? { ...option, voter_ids: [...option.voter_ids, me] }
                : option)),
        };
    }

    return next;
}

export class Channels {
    constructor(chat) {
        this.chat = chat;
        this.results = [];
        this.query = '';
        this.panel = null;
        this.panelConversationId = null;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="channels"]'),
            body: q('[data-channels-body]'),
            search: q('[data-channels-search]'),
        };

        if (!this.el.view || !chat.api.has('channels')) {
            document.querySelectorAll('[data-action="open-channels"], [data-action="new-channel"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            const { action } = event.detail;
            if (action === 'open-channels') this.open();
            if (action === 'close-channels') this.close();
            if (action === 'new-channel') this.createDialog();
            if (action === 'channel-info') this.openInfo(this.chat.active?.id);
            if (action === 'channel:unfollow') this.unfollow(this.chat.activeConversation());
            if (action === 'channel:delete') this.remove(this.chat.activeConversation());
        });

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (conversation?.type !== 'channel') return;
            items.unshift(html`<button type="button" class="dropdown-item" data-action="channel-info" role="menuitem">${raw(icon('rss'))} Channel info</button>`);
        });

        chat.el.headerUser?.addEventListener('click', () => {
            if (this.chat.activeConversation()?.type === 'channel') this.openInfo(this.chat.active.id);
        });
        document.addEventListener('chat:closed', () => this.closeInfo());

        const search = debounce((value) => this.search(value), 250);
        this.el.search?.addEventListener('input', (event) => search(event.target.value));
        this.el.body.addEventListener('click', (event) => this.onListClick(event));

        document.addEventListener('chat:sidebar-mode', (event) => {
            if (event.detail.mode !== 'channels' && !this.el.view.hidden) this.close({ silent: true });
        });
        document.addEventListener('app:back', (event) => {
            if (this.isOpen && !this.chat.active) {
                event.preventDefault();
                this.close();
            }
        });

        const invite = chat.config.channelInvite;
        if (invite) setTimeout(() => this.offerToFollow(invite), 300);
    }

    get isOpen() {
        return this.el.sidebar?.dataset.mode === 'channels';
    }

    /* ------------------------------------------------------------------ */
    /* Sidebar: channels I follow + find channels                          */
    /* ------------------------------------------------------------------ */

    async open() {
        if (this.chat.contactsPanel?.isOpen) this.chat.contactsPanel.close();
        if (this.chat.callLog?.isOpen) this.chat.callLog.close({ silent: true });
        if (this.chat.starred?.isOpen) this.chat.starred.close({ silent: true });
        if (this.chat.communities?.isOpen) this.chat.communities.close({ silent: true });
        this.el.sidebar.dataset.mode = 'channels';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'channels' } }));

        this.render();
        await this.search(this.el.search?.value ?? '');
    }

    close({ silent = false } = {}) {
        this.el.view.hidden = true;
        if (silent) return;
        this.el.chatsView.hidden = false;
        if (this.isOpen) this.el.sidebar.dataset.mode = 'chats';
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    followed() {
        return [...this.chat.conversations.values()]
            .filter((conversation) => conversation.type === 'channel' && conversation.channel?.is_following)
            .sort((a, b) => (b.last_message?.created_at ?? '').localeCompare(a.last_message?.created_at ?? ''));
    }

    async search(query) {
        this.query = String(query ?? '').trim();
        this.loading = true;
        this.render();
        try {
            this.results = (await this.chat.api.channels(this.query)).data ?? [];
            this.error = null;
        } catch (error) {
            this.results = [];
            this.error = errorMessage(error, "Couldn't load channels.");
        }
        this.loading = false;
        this.render();
    }

    render() {
        if (!this.el.body) return;
        const followed = this.followed();
        const followedIds = new Set(followed.map((conversation) => Number(conversation.id)));
        const needle = this.query.toLowerCase();
        const mine = needle ? followed.filter((conversation) => conversation.channel.name.toLowerCase().includes(needle)) : followed;
        const found = this.results.filter((channel) => !followedIds.has(Number(channel.id)) && !channel.is_following);

        const followedRow = (conversation) => html`
            <button type="button" class="channel-row" data-channel-open="${conversation.id}">
                ${raw(T.avatar(channelAvatar(conversation.id, conversation.channel), 'md'))}
                <span class="channel-row-body">
                    <span class="channel-row-name">${conversation.channel.name}${raw(conversation.channel.is_admin ? ' <span class="channel-badge">Admin</span>' : '')}</span>
                    <span class="channel-row-meta">${conversation.last_message && conversation.last_message.type !== 'system' ? conversation.last_message.preview : followersLabel(conversation.channel.followers_count)}</span>
                </span>
                ${raw(conversation.unread_count > 0 ? html`<span class="badge badge-primary">${conversation.unread_count > 99 ? '99+' : conversation.unread_count}</span>` : '')}
            </button>`;
        const foundRow = (channel) => html`
            <div class="channel-row">
                <button type="button" class="channel-row-main" data-channel-preview="${channel.id}">
                    ${raw(T.avatar(channelAvatar(channel.id, channel), 'md'))}
                    <span class="channel-row-body">
                        <span class="channel-row-name">${channel.name}</span>
                        <span class="channel-row-meta">${followersLabel(channel.followers_count)}${channel.description ? ` · ${channel.description}` : ''}</span>
                    </span>
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-channel-follow="${channel.id}">Follow</button>
            </div>`;

        this.el.body.innerHTML = html`
            <button type="button" class="channel-row channel-new-row" data-channel-new>
                <span class="avatar avatar-md"><span class="avatar-fallback is-accent">${raw(icon('plus'))}</span></span>
                <span class="channel-row-body"><span class="channel-row-name">Create channel</span><span class="channel-row-meta">Share updates with your followers</span></span>
            </button>
            ${raw(mine.length ? html`<div class="sidebar-section-title">Channels you follow</div>${raw(mine.map(followedRow).join(''))}` : '')}
            <div class="sidebar-section-title">${this.query ? 'Search results' : 'Find channels'}</div>
            ${raw(this.loading && !found.length
                ? '<div class="flex justify-center p-4 text-primary"><span class="spinner"></span></div>'
                : this.error
                  ? html`<p class="receipt-empty channel-empty">${this.error}</p>`
                  : found.length
                    ? found.map(foundRow).join('')
                    : html`<p class="receipt-empty channel-empty">${this.query ? `No channels match "${this.query}".` : 'No other channels yet.'}</p>`)}
        `;
    }

    async onListClick(event) {
        const t = event.target;
        if (t.closest('[data-channel-new]')) return this.createDialog();
        const follow = t.closest('[data-channel-follow]');
        if (follow) return this.follow(Number(follow.dataset.channelFollow), follow);
        const open = t.closest('[data-channel-open]');
        if (open) {
            this.close();
            return this.chat.openConversation(Number(open.dataset.channelOpen));
        }
        const preview = t.closest('[data-channel-preview]');
        if (preview) return this.preview(Number(preview.dataset.channelPreview));
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Preview, follow, unfollow                                           */
    /* ------------------------------------------------------------------ */

    /** A channel before following it: who it is and its latest updates. */
    async preview(id) {
        let channel;
        try {
            channel = await this.chat.api.channel(id);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't open the channel."));
            return;
        }

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal channel-preview';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'channel-preview-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-preview-close></div>
            <div class="modal-panel channel-preview-panel">
                <div class="channel-preview-hero">
                    ${raw(T.avatar(channelAvatar(channel.id, channel), 'xl'))}
                    <h2 class="modal-title" id="channel-preview-title">${channel.name}</h2>
                    <p class="group-info-count">Channel · ${followersLabel(channel.followers_count)}</p>
                    ${raw(channel.description ? html`<p class="group-info-description">${channel.description}</p>` : '')}
                </div>
                <div class="channel-preview-updates">
                    ${raw(channel.updates?.length
                        ? channel.updates.map((update) => html`
                            <div class="channel-update">
                                <p class="channel-update-text">${update.preview}</p>
                                <span class="channel-update-meta">${raw(update.reactions_count ? html`<span>${raw(icon('smile-plus', 'icon-xs'))} ${compactCount(update.reactions_count)}</span>` : '')}<time>${formatListTime(update.created_at)}</time></span>
                            </div>`).join('')
                        : '<p class="receipt-empty">No updates yet.</p>')}
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-preview-close>Close</button>
                    ${raw(channel.is_following
                        ? html`<button type="button" class="btn btn-primary" data-preview-open>Open channel</button>`
                        : html`<button type="button" class="btn btn-primary" data-preview-follow>${raw(icon('plus'))} Follow</button>`)}
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
            if (event.target.closest('[data-preview-close]')) return close();
            if (event.target.closest('[data-preview-open]')) {
                close();
                this.close();
                return this.chat.openConversation(channel.id);
            }
            const follow = event.target.closest('[data-preview-follow]');
            if (follow && (await this.follow(channel.id, follow))) close();
            return null;
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        const updates = overlay.querySelector('.channel-preview-updates');
        updates.scrollTop = updates.scrollHeight;
        overlay.querySelector('[data-preview-follow], [data-preview-open]')?.focus();
    }

    async follow(id, button = null) {
        if (button) button.disabled = true;
        try {
            const conversation = this.chat.upsertConversation(await this.chat.api.followChannel(id));
            toast.success(`You're following "${conversation.channel.name}".`);
            this.close();
            this.chat.openConversation(conversation.id);
            return true;
        } catch (error) {
            if (button) button.disabled = false;
            toast.error(errorMessage(error, "Couldn't follow the channel."));
            return false;
        }
    }

    async unfollow(conversation) {
        if (conversation?.type !== 'channel') return;
        const choice = await confirmDialog({
            title: `Unfollow "${conversation.channel.name}"?`,
            message: "You'll stop getting its updates. You can follow it again any time.",
            icon: 'user-minus',
            actions: [{ label: 'Unfollow', value: 'unfollow', variant: 'danger' }],
        });
        if (choice !== 'unfollow') return;

        try {
            await this.chat.api.unfollowChannel(conversation.id);
            this.closeInfo();
            this.chat.forgetConversation(conversation.id);
            toast.success(`You unfollowed "${conversation.channel.name}".`);
            if (this.isOpen) this.search(this.query);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't unfollow the channel."));
        }
    }

    async remove(conversation) {
        if (conversation?.type !== 'channel') return;
        const choice = await confirmDialog({
            title: `Delete "${conversation.channel.name}"?`,
            message: 'The channel and all its updates are deleted for every follower. This cannot be undone.',
            icon: 'trash-2',
            actions: [{ label: 'Delete channel', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;

        try {
            await this.chat.api.deleteChannel(conversation.id);
            this.closeInfo();
            this.chat.forgetConversation(conversation.id);
            toast.success('Channel deleted.');
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't delete the channel."));
        }
    }

    /** Someone changed the channel (or deleted it). */
    async onUpdated(id) {
        const conversation = await this.chat.refreshConversation(id);
        if (!conversation) {
            if (this.panelConversationId === id) this.closeInfo();
            this.chat.forgetConversation(id);
            return;
        }
        if (this.panelConversationId === id) this.renderInfo(conversation);
        if (this.isOpen) this.render();
    }

    /** Opened from a channel link. */
    async offerToFollow(invite) {
        history.replaceState(history.state, '', this.chat.config.routes.chat);
        if (!invite.valid) {
            toast.error('This channel link is no longer valid.');
            return;
        }
        const followed = this.chat.conversations.get(Number(invite.id));
        if (followed?.channel?.is_following) {
            this.chat.openConversation(followed.id);
            return;
        }
        this.preview(invite.id);
    }

    /* ------------------------------------------------------------------ */
    /* Channel info                                                        */
    /* ------------------------------------------------------------------ */

    openInfo(conversationId) {
        const conversation = this.chat.conversations.get(Number(conversationId));
        if (conversation?.type !== 'channel') return;

        this.closeInfo();
        const overlay = document.createElement('div');
        overlay.className = 'group-info';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Channel info');
        overlay.innerHTML = '<div class="group-info-backdrop" data-info-close></div><aside class="group-info-panel" data-info-body></aside>';
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.panelConversationId = Number(conversationId);
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onInfoClick(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) this.closeInfo();
        };
        document.addEventListener('keydown', this.onKey);
        this.renderInfo(conversation);
        overlay.querySelector('[data-info-close].btn-icon')?.focus();
    }

    closeInfo() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.panelConversationId = null;
        this.previousFocus?.focus?.();
    }

    renderInfo(conversation) {
        if (!this.panel) return;
        const channel = conversation.channel ?? {};

        this.panel.querySelector('[data-info-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-info-close aria-label="Close channel info">${raw(icon('x'))}</button>
                <span class="group-info-title">Channel info</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero">
                    ${raw(T.avatar(channelAvatar(conversation.id, channel), 'xl'))}
                    <h2 class="group-info-name">${channel.name}</h2>
                    <p class="group-info-count">Channel · ${followersLabel(channel.followers_count)}</p>
                </section>
                ${raw(channel.description ? html`<section class="group-info-section"><p class="group-info-description">${channel.description}</p></section>` : '')}
                <section class="group-info-section">
                    ${raw(channel.link ? html`<button type="button" class="group-info-action" data-channel-share>${raw(icon('share-2'))} Share channel link</button>` : '')}
                    ${raw(channel.is_admin ? html`<button type="button" class="group-info-action" data-channel-edit>${raw(icon('pencil'))} Edit channel</button>` : '')}
                    <p class="channel-privacy">${raw(icon('shield-off', 'icon-xs'))} Followers can't see each other, and updates show the channel's name, not the admin's.</p>
                </section>
                <section class="group-info-section group-info-danger">
                    ${raw(channel.is_admin
                        ? html`<button type="button" class="group-info-action is-danger" data-channel-delete>${raw(icon('trash-2'))} Delete channel</button>`
                        : html`<button type="button" class="group-info-action is-danger" data-channel-unfollow>${raw(icon('user-minus'))} Unfollow channel</button>`)}
                </section>
            </div>
        `;
    }

    onInfoClick(event) {
        const conversation = this.chat.conversations.get(this.panelConversationId);
        const t = event.target;
        if (t.closest('[data-info-close]')) return this.closeInfo();
        if (!conversation) return null;
        if (t.closest('[data-channel-share]')) {
            return this.chat.groupInvites?.openLink({
                name: conversation.channel.name,
                kind: 'channel',
                load: async () => ({ url: conversation.channel.link }),
                reset: null,
            });
        }
        if (t.closest('[data-channel-edit]')) return this.createDialog(conversation);
        if (t.closest('[data-channel-unfollow]')) return this.unfollow(conversation);
        if (t.closest('[data-channel-delete]')) return this.remove(conversation);
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Create / edit                                                       */
    /* ------------------------------------------------------------------ */

    createDialog(conversation = null) {
        const channel = conversation?.channel ?? null;
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'channel-form-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-form-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="channel-form-title">${channel ? 'Edit channel' : 'New channel'}</h2>
                ${raw(channel ? '' : '<p class="group-form-hint">Anyone can find and follow your channel. Followers get your updates but cannot reply.</p>')}
                <div class="group-form-row">
                    <label class="group-icon-picker" title="Channel icon">
                        <input type="file" accept="image/jpeg,image/png,image/webp" data-form-avatar hidden>
                        <span class="group-icon-preview" data-form-avatar-preview>${raw(channel?.avatar_url ? html`<img src="${channel.avatar_url}" alt="">` : icon('camera'))}</span>
                    </label>
                    <div class="group-form-fields">
                        <label class="form-label" for="channel-name">Channel name</label>
                        <input class="form-control" id="channel-name" name="name" maxlength="100" value="${channel?.name ?? ''}" autocomplete="off" required>
                    </div>
                </div>
                <label class="form-label" for="channel-description">Description (optional)</label>
                <textarea class="form-control" id="channel-description" name="description" rows="3" maxlength="512" placeholder="What is this channel about?">${channel?.description ?? ''}</textarea>
                <p class="poll-error" data-form-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-form-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">${channel ? 'Save' : 'Create channel'}</button>
                </div>
            </form>
        `;

        let avatar = null;
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };
        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-form-cancel]')) close();
        });
        overlay.querySelector('[data-form-avatar]').addEventListener('change', (event) => {
            avatar = event.target.files?.[0] ?? null;
            if (avatar) overlay.querySelector('[data-form-avatar-preview]').innerHTML = html`<img src="${URL.createObjectURL(avatar)}" alt="">`;
        });
        overlay.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const name = form.elements.namedItem('name').value.trim();
            const error = overlay.querySelector('[data-form-error]');
            if (!name) {
                error.hidden = false;
                error.textContent = 'Give the channel a name.';
                return;
            }
            const body = new FormData();
            body.append('name', name);
            body.append('description', form.elements.namedItem('description').value.trim());
            if (avatar) body.append('avatar', avatar);

            form.querySelector('[type="submit"]').disabled = true;
            try {
                const saved = this.chat.upsertConversation(conversation
                    ? await this.chat.api.updateChannel(conversation.id, body)
                    : await this.chat.api.createChannel(body));
                close();
                if (conversation) {
                    if (this.chat.active?.id === saved.id) this.chat.renderHeader(saved);
                    this.renderInfo(saved);
                } else {
                    if (this.isOpen) this.close();
                    this.chat.openConversation(saved.id);
                    toast.success('Channel created. Share its link so people can follow it.');
                }
            } catch (err) {
                error.hidden = false;
                error.textContent = errorMessage(err, "Couldn't save the channel.");
                form.querySelector('[type="submit"]').disabled = false;
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('#channel-name').focus();
    }
}
