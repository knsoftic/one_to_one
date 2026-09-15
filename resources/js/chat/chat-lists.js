import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { LABEL_COLORS, labelsFor } from './business';

/**
 * C6 — Favourites and your own chat lists ("Family", "Work"…) as filters above the chat list.
 */

export const MAX_LIST_NAME = 30;

/** Does a chat belong in the chosen filter? */
export function matchesFilter(conversation, filter, lists = []) {
    if (filter === 'unread') return (conversation.unread_count || 0) > 0 || Boolean(conversation.settings?.marked_unread);
    if (filter === 'favorites') return Boolean(conversation.settings?.favorite);
    if (String(filter).startsWith('list:')) {
        const list = lists.find((entry) => `list:${entry.id}` === filter);
        return Boolean(list?.conversation_ids.includes(Number(conversation.id)));
    }
    return true;
}

export class ChatLists {
    constructor(chat) {
        this.chat = chat;
        this.lists = [];
        this.chips = document.querySelector('[data-list-chips]');

        if (!chat.api.has('chatLists')) return;

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'new-chat-list') this.edit(null);
        });

        // Right click / long press on a list chip: edit or delete it.
        this.chips?.addEventListener('contextmenu', (event) => {
            const chip = event.target.closest('[data-filter^="list:"]');
            if (!chip) return;
            event.preventDefault();
            this.manage(Number(chip.dataset.filter.slice(5)));
        });
        let timer = null;
        this.chips?.addEventListener('touchstart', (event) => {
            const chip = event.target.closest('[data-filter^="list:"]');
            if (chip) timer = setTimeout(() => this.manage(Number(chip.dataset.filter.slice(5))), 500);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach((type) => this.chips?.addEventListener(type, () => clearTimeout(timer), { passive: true }));

        this.load();
    }

    async load() {
        try {
            this.lists = [...((await this.chat.api.chatLists()).data ?? [])];
        } catch {
            return;
        }
        // The chosen list may have been deleted on another device.
        if (String(this.chat.filter).startsWith('list:') && !this.lists.some((list) => `list:${list.id}` === this.chat.filter)) {
            this.chat.setFilter('all');
        }
        this.renderChips();
        this.chat.renderConversations();
    }

    renderChips() {
        if (!this.chips) return;
        this.chips.innerHTML = this.lists
            .map((list) => {
                const active = this.chat.filter === `list:${list.id}`;
                const dot = list.color ? `<span class="label-dot is-${list.color}" aria-hidden="true"></span>` : '';
                return html`<button type="button" class="chip${active ? ' is-active' : ''}" data-filter="list:${list.id}" role="tab" aria-selected="${active ? 'true' : 'false'}" title="Right-click to edit">${raw(dot)}${list.name}</button>`;
            })
            .join('');
    }

    /** X8: coloured lists work as labels on the chat rows. */
    labelsFor(conversationId) {
        return labelsFor(conversationId, this.lists);
    }

    byFilter(filter) {
        return this.lists.find((list) => `list:${list.id}` === filter) ?? null;
    }

    async manage(id) {
        const list = this.lists.find((entry) => entry.id === id);
        if (!list) return;
        const choice = await confirmDialog({
            title: list.name,
            message: `${list.conversation_ids.length} chat${list.conversation_ids.length === 1 ? '' : 's'} in this list.`,
            icon: 'tag',
            tone: 'primary',
            actions: [
                { label: 'Delete list', value: 'delete', variant: 'danger' },
                { label: 'Edit list', value: 'edit', variant: 'primary' },
            ],
        });
        if (choice === 'edit') this.edit(list);
        if (choice === 'delete') this.remove(list);
    }

    async remove(list) {
        try {
            await this.chat.api.deleteChatList(list.id);
            this.lists = this.lists.filter((entry) => entry.id !== list.id);
            if (this.chat.filter === `list:${list.id}`) this.chat.setFilter('all');
            this.renderChips();
            toast.success(`"${list.name}" deleted. The chats are not affected.`);
        } catch (error) {
            toast.error(errorMessage(error, 'The list could not be deleted.'));
        }
    }

    /**
     * Create (list = null) or edit a list: its name and which chats are in it.
     *
     * @param {object|null} list
     * @param {number[]} preselected chats ticked when creating from a chat's menu
     */
    edit(list, preselected = []) {
        const chats = this.chat.sortedConversations();
        const chosen = new Set(list ? list.conversation_ids : preselected);
        const previouslyFocused = document.activeElement;

        const overlay = document.createElement('div');
        overlay.className = 'modal chat-list-editor';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'chat-list-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-list-cancel></div>
            <form class="modal-panel chat-list-panel" data-list-form novalidate>
                <h2 class="modal-title" id="chat-list-title">${list ? 'Edit list' : 'New list'}</h2>
                <label class="form-label" for="chat-list-name">List name</label>
                <input class="form-control" id="chat-list-name" name="name" maxlength="${MAX_LIST_NAME}" placeholder="e.g. Family, Work" value="${list?.name ?? ''}" autocomplete="off" required>
                <fieldset class="label-colors">
                    <legend class="form-label">Label colour <span class="optional">(shows on the chats)</span></legend>
                    <label class="label-color" title="No colour"><input type="radio" name="color" value="" aria-label="No colour" ${raw(list?.color ? '' : 'checked')}><span class="label-swatch is-none">${raw(icon('x'))}</span></label>
                    ${raw(LABEL_COLORS.map((color) => html`<label class="label-color" title="${color}"><input type="radio" name="color" value="${color}" aria-label="${color}" ${raw(list?.color === color ? 'checked' : '')}><span class="label-swatch is-${color}"></span></label>`).join(''))}
                </fieldset>
                <div class="input-wrap">
                    ${raw(icon('search'))}
                    <input type="search" class="form-control" placeholder="Search chats" data-list-search aria-label="Search chats">
                </div>
                <div class="chat-list-choices" data-list-choices>
                    ${raw(chats.map((conversation) => {
                        const user = this.chat.participantOf(conversation) ?? {};
                        return html`
                            <label class="chat-list-choice" data-name="${String(user.name ?? '').toLowerCase()}">
                                <input type="checkbox" value="${conversation.id}" ${raw(chosen.has(conversation.id) ? 'checked' : '')}>
                                <span>${user.name ?? 'Chat'}</span>
                            </label>`;
                    }).join('') || html`<p class="sticker-empty">No chats yet.</p>`)}
                </div>
                <p class="poll-error" data-list-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-list-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">${list ? 'Save' : 'Create list'}</button>
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
            if (event.target.closest('[data-list-cancel]')) close();
        });
        overlay.querySelector('[data-list-search]').addEventListener('input', (event) => {
            const term = event.target.value.trim().toLowerCase();
            overlay.querySelectorAll('.chat-list-choice').forEach((row) => {
                row.hidden = Boolean(term) && !row.dataset.name.includes(term);
            });
        });

        overlay.querySelector('[data-list-form]').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const name = form.name.value.replace(/\s+/g, ' ').trim();
            const ids = [...overlay.querySelectorAll('.chat-list-choice input:checked')].map((input) => Number(input.value));
            const color = form.querySelector('input[name="color"]:checked')?.value || null;
            const error = overlay.querySelector('[data-list-error]');

            if (!name) {
                error.hidden = false;
                error.textContent = 'Give the list a name.';
                return;
            }

            try {
                const saved = list
                    ? await this.chat.api.updateChatList(list.id, { name, color, conversation_ids: ids })
                    : await this.chat.api.createChatList({ name, color, conversation_ids: ids });
                this.lists = list ? this.lists.map((entry) => (entry.id === saved.id ? saved : entry)) : [...this.lists, saved];
                close();
                this.renderChips();
                if (!list) this.chat.setFilter(`list:${saved.id}`);
                else this.chat.renderConversations();
            } catch (err) {
                error.hidden = false;
                error.textContent = errorMessage(err, 'The list could not be saved.');
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('#chat-list-name').focus();
    }

    /**
     * "Add to list" from a chat's menu: tick the lists this chat belongs to.
     */
    choose(conversation) {
        if (!this.lists.length) {
            this.edit(null, [conversation.id]);
            return;
        }

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal chat-list-editor';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'chat-list-choose-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-list-cancel></div>
            <form class="modal-panel chat-list-panel" data-list-form>
                <h2 class="modal-title" id="chat-list-choose-title">Add to list</h2>
                <div class="chat-list-choices">
                    ${raw(this.lists.map((list) => html`
                        <label class="chat-list-choice">
                            <input type="checkbox" value="${list.id}" ${raw(list.conversation_ids.includes(conversation.id) ? 'checked' : '')}>
                            ${raw(list.color ? `<span class="label-dot is-${list.color}" aria-hidden="true"></span>` : '')}
                            <span>${list.name}</span>
                        </label>`).join(''))}
                </div>
                <button type="button" class="btn btn-secondary btn-sm chat-list-new" data-list-new>${raw(icon('list-plus'))} New list</button>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-list-cancel>Cancel</button>
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
            if (event.target.closest('[data-list-cancel]')) close();
            if (event.target.closest('[data-list-new]')) {
                close();
                this.edit(null, [conversation.id]);
            }
        });

        overlay.querySelector('[data-list-form]').addEventListener('submit', async (event) => {
            event.preventDefault();
            const ticked = new Set([...overlay.querySelectorAll('input:checked')].map((input) => Number(input.value)));
            close();

            const changed = this.lists.filter((list) => list.conversation_ids.includes(conversation.id) !== ticked.has(list.id));
            try {
                for (const list of changed) {
                    const ids = ticked.has(list.id)
                        ? [...list.conversation_ids, conversation.id]
                        : list.conversation_ids.filter((id) => id !== conversation.id);
                    const saved = await this.chat.api.updateChatList(list.id, { conversation_ids: ids });
                    this.lists = this.lists.map((entry) => (entry.id === saved.id ? saved : entry));
                }
                if (changed.length) toast.success('Lists updated.');
                this.chat.renderConversations();
            } catch (error) {
                toast.error(errorMessage(error, 'The lists could not be updated.'));
                this.load();
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input')?.focus();
    }
}
