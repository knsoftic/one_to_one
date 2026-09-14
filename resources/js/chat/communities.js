import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import * as T from './templates';

/**
 * G10 — Communities: a sidebar view with your communities, each with its
 * announcements and groups (join the ones you're not in), plus admin tools.
 */

const avatarOf = (item) => ({ id: `community-${item.id}`, name: item.name, avatar_url: item.avatar_url, initials: item.initials, avatar_hue: item.avatar_hue });

const count = (n, word) => (n === 1 ? `1 ${word}` : `${n} ${word}s`);

/** Groups I run that could be added to a community (not in one already). */
export function linkableGroups(conversations) {
    return [...conversations]
        .filter((c) => c.type === 'group' && c.group?.is_member && c.group?.my_role === 'admin' && !c.group?.ended && !c.group?.community)
        .map((c) => ({ id: c.id, name: c.group.name }));
}

export class Communities {
    constructor(chat) {
        this.chat = chat;
        this.items = [];
        this.current = null;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="communities"]'),
            body: q('[data-communities-body]'),
            title: q('[data-communities-title]'),
        };

        if (!this.el.view || !chat.api.has('communities')) {
            document.querySelectorAll('[data-action="open-communities"], [data-action="new-community"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            const { action } = event.detail;
            if (action === 'open-communities') this.open();
            if (action === 'close-communities') this.back();
            if (action === 'new-community') this.createDialog();
        });

        this.el.body.addEventListener('click', (event) => this.onClick(event));

        document.addEventListener('chat:sidebar-mode', (event) => {
            if (event.detail.mode !== 'communities' && !this.el.view.hidden) this.close({ silent: true });
        });
        document.addEventListener('app:back', (event) => {
            if (this.isOpen && !this.chat.active) {
                event.preventDefault();
                this.back();
            }
        });

        const invite = chat.config.communityInvite;
        if (invite) setTimeout(() => this.offerToJoin(invite), 300);
    }

    get isOpen() {
        return this.el.sidebar?.dataset.mode === 'communities';
    }

    /* ------------------------------------------------------------------ */
    /* View                                                                */
    /* ------------------------------------------------------------------ */

    async open(communityId = null) {
        if (this.chat.contactsPanel?.isOpen) this.chat.contactsPanel.close();
        if (this.chat.callLog?.isOpen) this.chat.callLog.close({ silent: true });
        if (this.chat.starred?.isOpen) this.chat.starred.close({ silent: true });
        this.el.sidebar.dataset.mode = 'communities';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'communities' } }));

        this.el.body.innerHTML = '<div class="flex justify-center p-6 text-primary"><span class="spinner"></span></div>';
        await this.load();
        if (communityId) this.show(communityId);
        else this.renderList();
    }

    /** Back: from a community to the list, from the list to chats. */
    back() {
        if (this.current) {
            this.renderList();
            return;
        }
        this.close();
    }

    close({ silent = false } = {}) {
        this.current = null;
        this.el.view.hidden = true;
        if (silent) return;
        this.el.chatsView.hidden = false;
        if (this.isOpen) this.el.sidebar.dataset.mode = 'chats';
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    async load() {
        try {
            this.items = (await this.chat.api.communities()).data ?? [];
        } catch (error) {
            this.items = [];
            this.el.body.innerHTML = T.emptyState({ iconName: 'circle-alert', title: "Couldn't load communities", text: errorMessage(error) });
        }
    }

    renderList() {
        this.current = null;
        this.el.title.textContent = 'Communities';
        if (!this.items.length) {
            this.el.body.innerHTML = html`
                ${raw(T.emptyState({ iconName: 'users-round', title: 'Stay connected with a community', text: 'Communities bring groups together under one topic, with announcements for everyone.' }))}
                <div class="flex justify-center"><button type="button" class="btn btn-primary" data-community-new>${raw(icon('plus'))} New community</button></div>
            `;
            return;
        }

        this.el.body.innerHTML = html`
            <button type="button" class="community-new-row" data-community-new>
                <span class="avatar avatar-md"><span class="avatar-fallback is-accent">${raw(icon('users-round'))}</span></span>
                <span class="conversation-name">New community</span>
            </button>
            ${raw(this.items.map((item) => html`
                <button type="button" class="community-card" data-community="${item.id}">
                    ${raw(T.avatar(avatarOf(item), 'md'))}
                    <span class="community-card-body">
                        <span class="community-card-name">${item.name}</span>
                        <span class="community-card-meta">${count(item.groups.length, 'group')} · ${count(item.member_count, 'member')}</span>
                    </span>
                    ${raw(icon('chevron-right'))}
                </button>`).join(''))}
        `;
    }

    show(id) {
        const item = this.items.find((entry) => Number(entry.id) === Number(id));
        if (!item) {
            this.renderList();
            return;
        }
        this.current = item;
        this.el.title.textContent = item.name;

        const mine = item.groups.filter((g) => g.is_member);
        const others = item.groups.filter((g) => !g.is_member);
        const groupRow = (g, joined) => html`
            <div class="community-group">
                ${raw(T.avatar({ id: `group-${g.id}`, name: g.name, avatar_url: g.avatar_url, initials: g.initials, avatar_hue: g.avatar_hue, is_group: true }, 'md'))}
                <span class="community-card-body">
                    <span class="community-card-name">${g.name}</span>
                    <span class="community-card-meta">${count(g.member_count, 'member')}</span>
                </span>
                ${raw(joined
                    ? html`<button type="button" class="btn btn-secondary btn-sm" data-community-open="${g.id}">Open</button>`
                    : html`<button type="button" class="btn btn-primary btn-sm" data-community-join="${g.id}">Join</button>`)}
            </div>`;

        this.el.body.innerHTML = html`
            <section class="community-hero">
                ${raw(T.avatar(avatarOf(item), 'xl'))}
                <h2 class="group-info-name">${item.name}</h2>
                <p class="group-info-count">Community · ${count(item.member_count, 'member')}</p>
                ${raw(item.description ? html`<p class="group-info-description">${item.description}</p>` : '')}
            </section>
            <button type="button" class="community-announcements" data-community-open="${item.announcement_id}">
                <span class="avatar avatar-md"><span class="avatar-fallback is-accent">${raw(icon('megaphone'))}</span></span>
                <span class="community-card-body">
                    <span class="community-card-name">Announcements</span>
                    <span class="community-card-meta">${item.is_admin ? 'Post updates for everyone in the community' : 'Updates from the community admins'}</span>
                </span>
            </button>
            ${raw(item.is_admin ? html`
                <div class="community-actions">
                    <button type="button" class="group-info-action" data-community-add-group>${raw(icon('plus'))} New group</button>
                    <button type="button" class="group-info-action" data-community-link-group>${raw(icon('link-2'))} Add existing groups</button>
                    <button type="button" class="group-info-action" data-community-invite>${raw(icon('qr-code'))} Invite via link or QR code</button>
                    <button type="button" class="group-info-action" data-community-edit>${raw(icon('pencil'))} Edit community</button>
                </div>` : '')}
            ${raw(mine.length ? html`<div class="sidebar-section-title">Groups you're in</div>${raw(mine.map((g) => groupRow(g, true)).join(''))}` : '')}
            ${raw(others.length ? html`<div class="sidebar-section-title">Groups you can join</div>${raw(others.map((g) => groupRow(g, false)).join(''))}` : '')}
            ${raw(!item.groups.length ? html`<p class="receipt-empty community-empty">No groups yet.${item.is_admin ? ' Add one to get started.' : ''}</p>` : '')}
            <div class="community-actions">
                <button type="button" class="group-info-action is-danger" data-community-leave>${raw(icon('log-out'))} Exit community</button>
                ${raw(item.is_admin ? html`<button type="button" class="group-info-action is-danger" data-community-delete>${raw(icon('trash-2'))} Delete community</button>` : '')}
            </div>
        `;
    }

    async refresh() {
        const id = this.current?.id;
        await this.load();
        if (id && this.items.some((item) => Number(item.id) === Number(id))) this.show(id);
        else this.renderList();
    }

    /* ------------------------------------------------------------------ */
    /* Actions                                                             */
    /* ------------------------------------------------------------------ */

    async onClick(event) {
        const t = event.target;
        if (t.closest('[data-community-new]')) return this.createDialog();
        const card = t.closest('[data-community]');
        if (card) return this.show(Number(card.dataset.community));

        const item = this.current;
        if (!item) return null;

        const open = t.closest('[data-community-open]');
        if (open) {
            this.close();
            return this.chat.openConversation(Number(open.dataset.communityOpen));
        }
        const join = t.closest('[data-community-join]');
        if (join) return this.joinGroup(item, Number(join.dataset.communityJoin), join);
        if (t.closest('[data-community-add-group]')) return this.newGroup(item);
        if (t.closest('[data-community-link-group]')) return this.linkGroups(item);
        if (t.closest('[data-community-invite]')) {
            return this.chat.groupInvites?.openLink({
                name: item.name,
                kind: 'community',
                load: () => this.chat.api.communityInvite(item.id),
                reset: () => this.chat.api.resetCommunityInvite(item.id),
            });
        }
        if (t.closest('[data-community-edit]')) return this.createDialog(item);
        if (t.closest('[data-community-leave]')) return this.leave(item);
        if (t.closest('[data-community-delete]')) return this.remove(item);
        return null;
    }

    async joinGroup(item, groupId, button) {
        button.disabled = true;
        try {
            const conversation = await this.chat.api.joinCommunityGroup(item.id, groupId);
            this.chat.upsertConversation(conversation);
            toast.success(`You joined "${conversation.group.name}".`);
            await this.refresh();
        } catch (error) {
            button.disabled = false;
            toast.error(errorMessage(error, "Couldn't join the group."));
        }
    }

    newGroup(item) {
        this.nameDialog('New group in the community', 'e.g. Parking', async (name) => {
            try {
                const conversation = await this.chat.api.createCommunityGroup(item.id, { name });
                this.chat.upsertConversation(conversation);
                await this.refresh();
                toast.success(`"${name}" was added. People in the community can join it.`);
            } catch (error) {
                toast.error(errorMessage(error, "Couldn't create the group."));
            }
        });
    }

    async linkGroups(item) {
        const groups = linkableGroups(this.chat.conversations.values(), item.id);
        if (!groups.length) {
            toast.info('You have no other groups you are an admin of.');
            return;
        }
        const ids = await this.checkboxDialog('Add existing groups', groups);
        if (!ids?.length) return;
        for (const id of ids) {
            try {
                this.chat.upsertConversation(await this.chat.api.linkCommunityGroup(item.id, id));
            } catch (error) {
                toast.error(errorMessage(error, "Couldn't add a group."));
            }
        }
        await this.refresh();
    }

    async leave(item) {
        const choice = await confirmDialog({
            title: `Exit "${item.name}"?`,
            message: "You'll leave the community's announcements and every group of it.",
            icon: 'log-out',
            actions: [{ label: 'Exit community', value: 'exit', variant: 'danger' }],
        });
        if (choice !== 'exit') return;
        try {
            await this.chat.api.leaveCommunity(item.id);
            this.chat.loadConversations();
            await this.refresh();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't exit the community."));
        }
    }

    async remove(item) {
        const choice = await confirmDialog({
            title: `Delete "${item.name}"?`,
            message: 'The announcements end and its groups become normal groups again. Nobody is removed from the groups.',
            icon: 'trash-2',
            actions: [{ label: 'Delete community', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;
        try {
            await this.chat.api.deleteCommunity(item.id);
            this.chat.loadConversations();
            this.current = null;
            await this.refresh();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't delete the community."));
        }
    }

    /** New community (or edit one): name, description, icon, and groups to add. */
    createDialog(item = null) {
        const groups = item ? [] : linkableGroups(this.chat.conversations.values());
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'community-form-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-form-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="community-form-title">${item ? 'Edit community' : 'New community'}</h2>
                ${raw(item ? '' : '<p class="group-form-hint">Bring related groups together, with announcements for everyone.</p>')}
                <div class="group-form-row">
                    <label class="group-icon-picker" title="Community icon">
                        <input type="file" accept="image/jpeg,image/png,image/webp" data-form-avatar hidden>
                        <span class="group-icon-preview" data-form-avatar-preview>${raw(item?.avatar_url ? html`<img src="${item.avatar_url}" alt="">` : icon('camera'))}</span>
                    </label>
                    <div class="group-form-fields">
                        <label class="form-label" for="community-name">Community name</label>
                        <input class="form-control" id="community-name" name="name" maxlength="100" value="${item?.name ?? ''}" autocomplete="off" required>
                    </div>
                </div>
                <label class="form-label" for="community-description">Description (optional)</label>
                <textarea class="form-control" id="community-description" name="description" rows="2" maxlength="512">${item?.description ?? ''}</textarea>
                ${raw(groups.length ? html`
                    <span class="form-label">Add groups you run (optional)</span>
                    <div class="people-picker-list">${raw(groups.map((g) => html`<label class="people-picker-row"><input type="checkbox" value="${g.id}"><span>${g.name}</span></label>`).join(''))}</div>` : '')}
                <p class="poll-error" data-form-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-form-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">${item ? 'Save' : 'Create community'}</button>
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
                error.textContent = 'Give the community a name.';
                return;
            }
            const body = new FormData();
            body.append('name', name);
            body.append('description', form.elements.namedItem('description').value.trim());
            if (avatar) body.append('avatar', avatar);
            [...overlay.querySelectorAll('.people-picker-row input:checked')].forEach((box) => body.append('group_ids[]', box.value));

            form.querySelector('[type="submit"]').disabled = true;
            try {
                const saved = item ? await this.chat.api.updateCommunity(item.id, body) : await this.chat.api.createCommunity(body);
                close();
                this.chat.loadConversations();
                await this.open(saved.id);
            } catch (err) {
                error.hidden = false;
                error.textContent = errorMessage(err, "Couldn't save the community.");
                form.querySelector('[type="submit"]').disabled = false;
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('#community-name').focus();
    }

    nameDialog(title, placeholder, onSave) {
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'community-name-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-name-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="community-name-title">${title}</h2>
                <input class="form-control" name="value" maxlength="100" placeholder="${placeholder}" autocomplete="off" required>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-name-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Create</button>
                </div>
            </form>
        `;
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };
        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-name-cancel]')) close();
        });
        overlay.querySelector('form').addEventListener('submit', (event) => {
            event.preventDefault();
            const value = event.target.elements.namedItem('value').value.trim();
            if (!value) return;
            close();
            onSave(value);
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input').focus();
    }

    checkboxDialog(title, items) {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'modal';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'community-pick-title');
            overlay.innerHTML = html`
                <div class="modal-backdrop" data-pick-cancel></div>
                <form class="modal-panel group-form">
                    <h2 class="modal-title" id="community-pick-title">${title}</h2>
                    <div class="people-picker-list">${raw(items.map((g) => html`<label class="people-picker-row"><input type="checkbox" value="${g.id}"><span>${g.name}</span></label>`).join(''))}</div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-pick-cancel>Cancel</button>
                        <button type="submit" class="btn btn-primary">Add</button>
                    </div>
                </form>
            `;
            const close = (value) => {
                overlay.remove();
                resolve(value);
            };
            overlay.addEventListener('click', (event) => {
                if (event.target.closest('[data-pick-cancel]')) close(null);
            });
            overlay.querySelector('form').addEventListener('submit', (event) => {
                event.preventDefault();
                close([...overlay.querySelectorAll('input:checked')].map((box) => Number(box.value)));
            });
            document.body.appendChild(overlay);
        });
    }

    /** Opened from a community invite link. */
    async offerToJoin(invite) {
        history.replaceState(history.state, '', this.chat.config.routes.chat);
        if (!invite.valid) {
            toast.error('This invite link is no longer valid. Ask a community admin for a new one.');
            return;
        }
        if (invite.is_member) {
            this.open(invite.id);
            return;
        }

        const choice = await confirmDialog({
            title: `Join "${invite.name}"?`,
            message: `${count(invite.member_count, 'member')}. You'll get the community's announcements and can join its groups.`,
            icon: 'users-round',
            tone: 'primary',
            cancelLabel: 'Not now',
            actions: [{ label: 'Join community', value: 'join', variant: 'primary' }],
        });
        if (choice !== 'join') return;

        try {
            const community = await this.chat.api.joinCommunity(invite.token);
            this.chat.loadConversations();
            this.open(community.id);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't join the community."));
        }
    }
}
