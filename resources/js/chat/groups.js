import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { pickPeople } from './people-picker';
import * as T from './templates';

/**
 * Phase 4 — Group chats: create a group (G1), group info with people and admins (G2),
 * settings (G5), exit / delete (G8).
 */

/** Short list of who is in a group for the chat header: "You, Ayesha, Bilal". */
export function groupSummary(group, meId, nameOf) {
    const members = (group?.members ?? []).filter((m) => m.active);
    // Community announcements (G10): members don't see who else is in the community.
    if (!members.length || group?.members_hidden) return memberCount(group?.member_count ?? members.length);

    const names = members
        .sort((a, b) => Number(Number(b.user.id) === Number(meId)) - Number(Number(a.user.id) === Number(meId)))
        .map((m) => (Number(m.user.id) === Number(meId) ? 'You' : nameOf(m.user.id, m.user.name)));
    const text = names.join(', ');
    return text.length > 70 ? `${text.slice(0, 67)}…` : text;
}

export const memberCount = (count) => (Number(count) === 1 ? '1 member' : `${Number(count) || 0} members`);

/** Can I change the group's name, icon and description, and add people? */
export const canEditInfo = (group) => Boolean(group?.can_edit_info);
export const isGroupAdmin = (group) => group?.my_role === 'admin' && Boolean(group?.is_member);

export class Groups {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        this.panelConversationId = null;

        if (!chat.api.has('groupsStore')) {
            document.querySelectorAll('[data-action="new-group"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            const { action } = event.detail;
            if (action === 'new-group') this.create();
            if (action === 'group-info') this.openInfo(this.chat.active?.id);
            if (action === 'group:exit') this.leave(this.chat.activeConversation());
        });

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (conversation?.type !== 'group') return;
            items.unshift(html`<button type="button" class="dropdown-item" data-action="group-info" role="menuitem">${raw(icon('info'))} Group info</button>`);
        });

        // The name and icon in the chat header open group info.
        chat.el.headerUser.addEventListener('click', () => {
            if (this.chat.activeConversation()?.type === 'group') this.openInfo(this.chat.active.id);
        });

        document.addEventListener('chat:closed', () => this.closeInfo());
    }

    /** Realtime: the group changed (name, people, roles, settings). */
    async onUpdated(conversationId) {
        const conversation = await this.chat.refreshConversation(conversationId);
        if (conversation && this.panelConversationId === conversationId) this.renderInfo(conversation);
    }

    /* ------------------------------------------------------------------ */
    /* G1 — Create                                                         */
    /* ------------------------------------------------------------------ */

    async create() {
        const max = Number(this.chat.config.groups?.maxMembers ?? 256) - 1;
        const choice = await pickPeople(this.chat, { title: 'Add group members', max, submitLabel: 'Next' });
        if (!choice) return;
        this.details(choice.ids);
    }

    details(memberIds) {
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal group-create';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'group-create-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-group-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="group-create-title">New group</h2>
                <p class="group-form-hint">${memberIds.length === 1 ? '1 person' : `${memberIds.length} people`} and you</p>
                <div class="group-form-row">
                    <label class="group-icon-picker" title="Add group icon">
                        <input type="file" accept="image/jpeg,image/png,image/webp" data-group-avatar hidden>
                        <span class="group-icon-preview" data-group-avatar-preview>${raw(icon('camera'))}</span>
                    </label>
                    <div class="group-form-fields">
                        <label class="form-label" for="group-name">Group name</label>
                        <input class="form-control" id="group-name" name="name" maxlength="100" placeholder="e.g. Family" autocomplete="off" required>
                    </div>
                </div>
                <label class="form-label" for="group-description">Description (optional)</label>
                <textarea class="form-control" id="group-description" name="description" rows="2" maxlength="512" placeholder="What is this group about?"></textarea>
                <p class="poll-error" data-group-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-group-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Create group</button>
                </div>
            </form>
        `;

        let avatarFile = null;
        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-group-cancel]')) close();
        });
        overlay.querySelector('[data-group-avatar]').addEventListener('change', (event) => {
            avatarFile = event.target.files?.[0] ?? null;
            const preview = overlay.querySelector('[data-group-avatar-preview]');
            preview.innerHTML = avatarFile ? html`<img src="${URL.createObjectURL(avatarFile)}" alt="">` : icon('camera');
        });

        overlay.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const submit = form.querySelector('[type="submit"]');
            const error = overlay.querySelector('[data-group-error]');
            const field = (key) => form.elements.namedItem(key);
            const name = field('name').value.trim();
            if (!name) {
                error.hidden = false;
                error.textContent = 'Give the group a name.';
                return;
            }

            const body = new FormData();
            body.append('name', name);
            if (field('description').value.trim()) body.append('description', field('description').value.trim());
            memberIds.forEach((id) => body.append('member_ids[]', String(id)));
            if (avatarFile) body.append('avatar', avatarFile);

            submit.disabled = true;
            try {
                const conversation = await this.chat.api.createGroup(body);
                close();
                this.chat.upsertConversation(conversation);
                this.chat.contactsPanel?.isOpen && this.chat.contactsPanel.close();
                this.chat.openConversation(conversation.id);
            } catch (err) {
                error.hidden = false;
                error.textContent = errorMessage(err, "Couldn't create the group.");
                submit.disabled = false;
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('#group-name').focus();
    }

    /* ------------------------------------------------------------------ */
    /* Group info panel                                                    */
    /* ------------------------------------------------------------------ */

    async openInfo(conversationId) {
        if (!conversationId) return;
        let conversation = this.chat.conversations.get(Number(conversationId));
        if (!conversation?.group?.members) conversation = await this.chat.refreshConversation(Number(conversationId));
        if (!conversation?.group) return;

        this.closeInfo();
        const overlay = document.createElement('div');
        overlay.className = 'group-info';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Group info');
        overlay.innerHTML = '<div class="group-info-backdrop" data-info-close></div><aside class="group-info-panel" data-info-body></aside>';
        document.body.appendChild(overlay);

        this.panel = overlay;
        this.panelConversationId = Number(conversationId);
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onPanelClick(event));
        overlay.addEventListener('change', (event) => this.onPanelChange(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal')) this.closeInfo();
        };
        document.addEventListener('keydown', this.onKey);

        this.renderInfo(conversation);
        overlay.querySelector('[data-info-close-btn]')?.focus();
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
        const group = conversation.group;
        const me = Number(this.chat.me.id);
        const admin = isGroupAdmin(group);
        const editable = canEditInfo(group);
        const members = (group.members ?? []).filter((m) => m.active);
        // Community announcements (G10): members only see the admins and themselves.
        const hidden = Boolean(group.members_hidden);
        const total = hidden ? Number(group.member_count ?? members.length) : members.length;
        const nameOf = (user) => (Number(user.id) === me ? 'You' : this.chat.displayName(user.id, user.name));
        const created = group.created_at ? new Date(group.created_at).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' }) : '';
        const creator = (group.members ?? []).find((m) => m.is_creator)?.user;

        const memberRows = members.map((member) => {
            const user = this.chat.presenceOf(member.user);
            const isMe = Number(user.id) === me;
            const secondary = !isMe && member.user.saved_name && member.user.saved_name !== member.user.name ? `~${member.user.name}` : `@${member.user.username ?? ''}`;
            return html`
                <button type="button" class="group-member" data-member="${user.id}" ${raw(isMe ? 'disabled' : '')}>
                    ${raw(T.avatar({ ...user, name: nameOf(user) }, 'md', { status: !isMe }))}
                    <span class="group-member-body">
                        <span class="group-member-name">${nameOf(user)}</span>
                        <span class="group-member-meta">${secondary}</span>
                    </span>
                    ${raw(member.role === 'admin' ? html`<span class="group-admin-badge">${group.community?.is_announcement ? 'Community admin' : 'Group admin'}</span>` : '')}
                </button>`;
        }).join('');

        this.panel.querySelector('[data-info-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-info-close data-info-close-btn aria-label="Close group info">${raw(icon('x'))}</button>
                <span class="group-info-title">Group info</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero">
                    <label class="group-info-avatar${editable ? ' is-editable' : ''}" title="${editable ? 'Change group icon' : ''}">
                        ${raw(T.avatar(this.chat.participantOf(conversation), 'xl'))}
                        ${raw(editable ? html`<input type="file" accept="image/jpeg,image/png,image/webp" data-info-avatar hidden><span class="group-info-avatar-edit">${raw(icon('camera'))}</span>` : '')}
                    </label>
                    <div class="group-info-name-row">
                        <h2 class="group-info-name">${group.name}</h2>
                        ${raw(editable ? html`<button type="button" class="btn-icon btn-icon-sm" data-info-edit="name" aria-label="Change group name">${raw(icon('pencil'))}</button>` : '')}
                    </div>
                    <p class="group-info-count">${group.community?.is_announcement ? 'Community announcements' : 'Group'} · ${memberCount(total)}</p>
                    ${raw(group.community ? html`<button type="button" class="group-community-link" data-info-community="${group.community.id}">${raw(icon('users-round'))} ${group.community.name}</button>` : '')}
                    ${raw(!group.is_member ? html`<p class="group-info-notice">${group.ended ? 'This group was deleted.' : "You're no longer a member of this group."}</p>` : '')}
                </section>

                <section class="group-info-section">
                    <div class="group-info-row">
                        <span class="group-info-label">Description</span>
                        ${raw(editable ? html`<button type="button" class="btn-icon btn-icon-sm" data-info-edit="description" aria-label="Change description">${raw(icon('pencil'))}</button>` : '')}
                    </div>
                    <p class="group-info-description${group.description ? '' : ' is-empty'}">${group.description || (editable ? 'Add group description' : 'No description')}</p>
                    <p class="group-info-created">${creator ? `Created by ${nameOf(creator)}` : 'Created'}${created ? `, ${created}` : ''}</p>
                </section>

                ${raw(admin ? html`
                <section class="group-info-section">
                    <span class="group-info-label">Group settings</span>
                    <label class="group-setting">
                        <span><strong>Send messages</strong><small>Only admins can send messages when this is on</small></span>
                        <span class="switch"><input type="checkbox" data-info-setting="only_admins_send" ${raw(group.only_admins_send ? 'checked' : '')}><span class="switch-track"></span></span>
                    </label>
                    <label class="group-setting">
                        <span><strong>Edit group info</strong><small>Only admins can change the name, icon, description and add people</small></span>
                        <span class="switch"><input type="checkbox" data-info-setting="only_admins_edit" ${raw(group.only_admins_edit ? 'checked' : '')}><span class="switch-track"></span></span>
                    </label>
                    ${raw(this.chat.api.has('groupInvite') ? html`<button type="button" class="group-info-action" data-info-invite>${raw(icon('link-2'))} Invite via link or QR code</button>` : '')}
                </section>` : '')}

                ${raw(this.chat.media && group.is_member ? html`
                <section class="group-info-section">
                    <button type="button" class="group-info-action" data-info-media>${raw(icon('images'))} Media, links and docs</button>
                </section>` : '')}

                <section class="group-info-section">
                    <div class="group-info-row">
                        <span class="group-info-label">${hidden ? (members.some((m) => m.role === 'admin') ? 'Community admins' : 'You') : memberCount(total)}</span>
                    </div>
                    ${raw(hidden ? html`<p class="group-info-private" data-members-hidden>${raw(icon('lock'))} Only community admins can see everyone in the community. Other members can't see you.</p>` : '')}
                    ${raw(editable && !hidden ? html`<button type="button" class="group-info-action" data-info-add>${raw(icon('user-plus'))} Add members</button>` : '')}
                    <div class="group-members">${raw(memberRows)}</div>
                </section>

                <section class="group-info-section group-info-danger">
                    ${raw(group.is_member ? html`<button type="button" class="group-info-action is-danger" data-info-exit>${raw(icon('log-out'))} Exit group</button>` : '')}
                    ${raw(admin ? html`<button type="button" class="group-info-action is-danger" data-info-delete>${raw(icon('trash-2'))} Delete group for everyone</button>` : '')}
                    ${raw(!group.is_member ? html`<button type="button" class="group-info-action is-danger" data-info-delete-chat>${raw(icon('trash-2'))} Delete chat</button>` : '')}
                </section>
            </div>
        `;
    }

    conversation() {
        return this.chat.conversations.get(this.panelConversationId);
    }

    async onPanelClick(event) {
        const conversation = this.conversation();
        if (!conversation) return;
        const target = event.target;

        if (target.closest('[data-info-close]')) return this.closeInfo();
        if (target.closest('[data-info-edit]')) return this.editField(conversation, target.closest('[data-info-edit]').dataset.infoEdit);
        if (target.closest('[data-info-add]')) return this.addMembers(conversation);
        if (target.closest('[data-info-exit]')) return this.leave(conversation);
        if (target.closest('[data-info-delete]')) return this.endGroup(conversation);
        if (target.closest('[data-info-invite]')) return this.chat.groupInvites?.open(conversation);
        if (target.closest('[data-info-media]')) {
            this.closeInfo();
            return this.chat.media?.open(conversation.id);
        }
        const community = target.closest('[data-info-community]');
        if (community) {
            this.closeInfo();
            return this.chat.communities?.open(Number(community.dataset.infoCommunity));
        }
        if (target.closest('[data-info-delete-chat]')) {
            this.closeInfo();
            return this.chat.chatList?.remove(conversation);
        }
        const member = target.closest('[data-member]');
        if (member) return this.memberMenu(conversation, Number(member.dataset.member), member);
        return null;
    }

    async onPanelChange(event) {
        const conversation = this.conversation();
        if (!conversation) return;

        const avatarInput = event.target.closest('[data-info-avatar]');
        if (avatarInput?.files?.[0]) {
            const body = new FormData();
            body.append('avatar', avatarInput.files[0]);
            await this.save(conversation, () => this.chat.api.updateGroup(conversation.id, body), "Couldn't change the group icon.");
            return;
        }

        const setting = event.target.closest('[data-info-setting]');
        if (setting) {
            await this.save(conversation, () => this.chat.api.groupSettings(conversation.id, { [setting.dataset.infoSetting]: setting.checked }), "Couldn't change the group settings.");
        }
    }

    async save(conversation, request, failure) {
        try {
            const updated = await request();
            const merged = this.chat.upsertConversation(updated);
            if (this.chat.active?.id === merged.id) {
                this.chat.renderHeader(merged);
                this.chat.updateComposerState(merged);
            }
            this.renderInfo(merged);
            return merged;
        } catch (error) {
            toast.error(errorMessage(error, failure));
            this.renderInfo(this.chat.conversations.get(conversation.id) ?? conversation);
            return null;
        }
    }

    editField(conversation, field) {
        const group = conversation.group;
        const isName = field === 'name';
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'group-edit-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-edit-cancel></div>
            <form class="modal-panel group-form" novalidate>
                <h2 class="modal-title" id="group-edit-title">${isName ? 'Group name' : 'Group description'}</h2>
                ${raw(isName
                    ? html`<input class="form-control" name="value" maxlength="100" value="${group.name ?? ''}" autocomplete="off" required>`
                    : html`<textarea class="form-control" name="value" rows="4" maxlength="512" placeholder="Add group description">${group.description ?? ''}</textarea>`)}
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-edit-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
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
            if (event.target.closest('[data-edit-cancel]')) close();
        });
        overlay.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const value = event.target.elements.namedItem('value').value.trim();
            if (isName && !value) return;
            close();
            const body = new FormData();
            body.append(field, value);
            await this.save(conversation, () => this.chat.api.updateGroup(conversation.id, body), "Couldn't save the change.");
        });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[name="value"]').focus();
    }

    async addMembers(conversation) {
        const group = conversation.group;
        const current = (group.members ?? []).filter((m) => m.active).map((m) => Number(m.user.id));
        const room = Math.max(0, Number(group.max_members ?? 256) - current.length);
        if (!room) {
            toast.info('This group is full.');
            return;
        }
        const choice = await pickPeople(this.chat, { title: 'Add members', max: room, excludeIds: current, submitLabel: 'Add' });
        if (!choice) return;
        await this.save(conversation, () => this.chat.api.addGroupMembers(conversation.id, choice.ids), "Couldn't add them to the group.");
    }

    /* ------------------------------------------------------------------ */
    /* G2 — A member's options                                             */
    /* ------------------------------------------------------------------ */

    memberMenu(conversation, userId, anchor) {
        const group = conversation.group;
        const member = (group.members ?? []).find((m) => Number(m.user.id) === userId);
        if (!member) return;
        const name = this.chat.displayName(userId, member.user.name);
        const admin = isGroupAdmin(group);

        const items = [{ action: 'message', icon: 'message-circle', label: `Message ${name}` }];
        if (admin) {
            items.push(member.role === 'admin'
                ? { action: 'dismiss', icon: 'shield-off', label: 'Dismiss as admin' }
                : { action: 'promote', icon: 'shield-check', label: 'Make group admin' });
            if (!member.is_creator) items.push({ action: 'remove', icon: 'user-minus', label: `Remove ${name}`, danger: true });
        }

        this.panel.querySelector('.group-member-menu')?.remove();
        const menu = document.createElement('div');
        menu.className = 'dropdown-menu group-member-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = items.map((item) => html`<button type="button" class="dropdown-item${item.danger ? ' is-danger' : ''}" data-member-action="${item.action}" role="menuitem">${raw(icon(item.icon))} ${item.label}</button>`).join('');
        anchor.after(menu);
        menu.querySelector('button')?.focus();

        const dismiss = (event) => {
            if (!menu.contains(event.target)) {
                menu.remove();
                document.removeEventListener('pointerdown', dismiss, true);
            }
        };
        document.addEventListener('pointerdown', dismiss, true);

        menu.addEventListener('click', async (event) => {
            const action = event.target.closest('[data-member-action]')?.dataset.memberAction;
            if (!action) return;
            menu.remove();
            document.removeEventListener('pointerdown', dismiss, true);

            if (action === 'message') {
                this.closeInfo();
                this.chat.startConversationWith(userId);
            } else if (action === 'promote' || action === 'dismiss') {
                await this.save(conversation, () => this.chat.api.setGroupRole(conversation.id, userId, action === 'promote' ? 'admin' : 'member'), "Couldn't change the admin.");
            } else if (action === 'remove') {
                const choice = await confirmDialog({
                    title: `Remove ${name} from "${group.name}"?`,
                    icon: 'user-minus',
                    actions: [{ label: 'Remove', value: 'remove', variant: 'danger' }],
                });
                if (choice === 'remove') {
                    await this.save(conversation, () => this.chat.api.removeGroupMember(conversation.id, userId), `Couldn't remove ${name}.`);
                }
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* G8 — Exit and delete                                                */
    /* ------------------------------------------------------------------ */

    async leave(conversation) {
        if (!conversation?.group?.is_member) return;
        const choice = await confirmDialog({
            title: `Exit "${conversation.group.name}"?`,
            message: 'You will no longer get messages from this group. You can still read what was sent while you were in it.',
            icon: 'log-out',
            actions: [{ label: 'Exit group', value: 'exit', variant: 'danger' }],
        });
        if (choice !== 'exit') return;
        const merged = await this.save(conversation, () => this.chat.api.leaveGroup(conversation.id), "Couldn't exit the group.");
        if (merged) toast.success(`You left "${merged.group.name}".`);
    }

    async endGroup(conversation) {
        const choice = await confirmDialog({
            title: `Delete "${conversation.group.name}" for everyone?`,
            message: 'Everyone is removed from the group and nobody can send messages any more. People keep what was already sent until they delete the chat.',
            icon: 'trash-2',
            actions: [{ label: 'Delete group', value: 'delete', variant: 'danger' }],
        });
        if (choice !== 'delete') return;
        await this.save(conversation, () => this.chat.api.deleteGroup(conversation.id), "Couldn't delete the group.");
    }
}
