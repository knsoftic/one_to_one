import { html, raw } from '../lib/dom';
import * as T from './templates';

/**
 * G4 — @mentions in group chats: type "@" in the composer to pick someone from the
 * group; mentioned people are highlighted in the message and notified even when
 * they muted the group.
 */

/** The "@word" being typed right before the cursor, or null. */
export function mentionQuery(text, caret) {
    const before = text.slice(0, caret);
    const match = before.match(/(^|\s)@([^\s@]{0,30})$/u);
    return match ? { query: match[2], start: caret - match[2].length - 1 } : null;
}

/** People to suggest for a query: active members except me, names starting or containing it. */
export function mentionCandidates(conversation, meId, query, nameOf) {
    const q = query.toLowerCase();
    return (conversation?.group?.members ?? [])
        .filter((m) => m.active && Number(m.user.id) !== Number(meId))
        .map((m) => ({ id: Number(m.user.id), name: nameOf(m.user.id, m.user.name), user: m.user }))
        .filter((person) => !q || person.name.toLowerCase().split(/\s+/).some((word) => word.startsWith(q)) || person.name.toLowerCase().includes(q))
        .sort((a, b) => Number(!a.name.toLowerCase().startsWith(q)) - Number(!b.name.toLowerCase().startsWith(q)) || a.name.localeCompare(b.name))
        .slice(0, 8);
}

/** Mentions whose "@Name" is still in the text when sending. */
export function mentionsInText(text, picked) {
    return [...picked.values()].filter((mention) => text.includes(`@${mention.name}`));
}

export class Mentions {
    constructor(chat) {
        this.chat = chat;
        /** @type {Map<number, {id: number, name: string}>} */
        this.picked = new Map();
        this.state = null;

        const { composerInput, composerForm } = chat.el;
        this.popup = document.createElement('div');
        this.popup.className = 'mention-popup';
        this.popup.setAttribute('role', 'listbox');
        this.popup.setAttribute('aria-label', 'Mention someone');
        this.popup.hidden = true;
        composerForm.parentElement.insertBefore(this.popup, composerForm);

        composerInput.addEventListener('input', () => this.update());
        composerInput.addEventListener('click', () => this.update());
        composerInput.addEventListener('blur', () => setTimeout(() => this.close(), 150));

        // Capture: runs before the composer's own Enter-to-send.
        composerForm.addEventListener('keydown', (event) => this.onKey(event), true);

        this.popup.addEventListener('mousedown', (event) => event.preventDefault());
        this.popup.addEventListener('click', (event) => {
            const option = event.target.closest('[data-mention-id]');
            if (option) this.insert(Number(option.dataset.mentionId));
        });

        document.addEventListener('chat:compose-payload', (event) => {
            const payload = event.detail.payload;
            const text = String(payload.message ?? '');
            const mentions = mentionsInText(text, this.picked);
            if (mentions.length) payload.mentions = mentions;
            this.picked.clear();
        });
        document.addEventListener('chat:opened', () => {
            this.picked.clear();
            this.close();
        });

        // Tapping a mention opens a chat with that person.
        chat.el.messageList.addEventListener('click', (event) => {
            const mention = event.target.closest('[data-mention-user]');
            if (mention && Number(mention.dataset.mentionUser) !== Number(chat.me.id)) {
                chat.startConversationWith(Number(mention.dataset.mentionUser));
            }
        });
    }

    update() {
        const conversation = this.chat.activeConversation();
        const input = this.chat.el.composerInput;
        if (conversation?.type !== 'group' || !conversation.group?.can_send) return this.close();

        const found = mentionQuery(input.value, input.selectionStart ?? input.value.length);
        if (!found) return this.close();

        const candidates = mentionCandidates(conversation, this.chat.me.id, found.query, (id, name) => this.chat.displayName(id, name));
        if (!candidates.length) return this.close();

        const index = this.state && this.state.query === found.query ? Math.min(this.state.index, candidates.length - 1) : 0;
        this.state = { ...found, candidates, index };
        this.render();
        return null;
    }

    render() {
        const { candidates, index } = this.state;
        this.popup.hidden = false;
        this.popup.innerHTML = candidates
            .map((person, i) => html`
                <button type="button" class="mention-option${i === index ? ' is-active' : ''}" data-mention-id="${person.id}" role="option" aria-selected="${i === index ? 'true' : 'false'}">
                    ${raw(T.avatar(this.chat.presenceOf(person.user), 'sm'))}
                    <span class="mention-option-name">${person.name}</span>
                    <span class="mention-option-meta">@${person.user.username ?? ''}</span>
                </button>`)
            .join('');
    }

    onKey(event) {
        if (!this.state || this.popup.hidden) return;
        const { candidates } = this.state;

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.state.index = (this.state.index + (event.key === 'ArrowDown' ? 1 : -1) + candidates.length) % candidates.length;
            this.render();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.insert(candidates[this.state.index].id);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.close();
        }
    }

    insert(userId) {
        const person = this.state?.candidates.find((candidate) => candidate.id === userId);
        if (!person) return;
        const input = this.chat.el.composerInput;
        const caret = input.selectionStart ?? input.value.length;
        input.setRangeText(`@${person.name} `, this.state.start, caret, 'end');
        this.picked.set(person.id, { id: person.id, name: person.name });
        this.close();
        input.focus();
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    close() {
        this.state = null;
        this.popup.hidden = true;
        this.popup.innerHTML = '';
    }
}
