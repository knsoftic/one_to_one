import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import * as T from './templates';

/**
 * People to call: saved contacts on the app and people from recent chats, once each.
 *
 * @returns {{id: number, name: string, user: object}[]}
 */
export function callablePeople(chat, excludeIds = []) {
    const exclude = new Set([Number(chat.me?.id), ...excludeIds.map(Number)]);
    const people = new Map();

    for (const contact of chat.contactsPanel?.contacts ?? []) {
        const user = contact.user;
        if (user && !exclude.has(Number(user.id))) people.set(Number(user.id), { id: Number(user.id), name: contact.name || user.name, user });
    }
    for (const conversation of chat.sortedConversations?.() ?? []) {
        const user = conversation.participant;
        if (!user || conversation.is_self || conversation.blocked_by_me || conversation.blocked_me || exclude.has(Number(user.id)) || people.has(Number(user.id))) continue;
        const person = chat.participantOf?.(conversation) ?? user;
        people.set(Number(user.id), { id: Number(user.id), name: person.name, user: person });
    }

    return [...people.values()].sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * Choose up to $max people for a group call (K6).
 *
 * @param {object} chat
 * @param {{title: string, max: number, excludeIds?: number[], submitLabel?: string, chooseType?: boolean}} options
 * @returns {Promise<{ids: number[], type: 'audio'|'video'}|null>}
 */
export function pickPeople(chat, { title, max, excludeIds = [], submitLabel = 'Add', chooseType = false }) {
    return new Promise((resolve) => {
        const people = callablePeople(chat, excludeIds);
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal people-picker';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'people-picker-title');

        overlay.innerHTML = html`
            <div class="modal-backdrop" data-picker-cancel></div>
            <form class="modal-panel people-picker-panel" novalidate>
                <h2 class="modal-title" id="people-picker-title">${title}</h2>
                <p class="people-picker-hint" data-picker-hint></p>
                <div class="input-wrap">
                    ${raw(icon('search'))}
                    <input type="search" class="form-control" placeholder="Search" data-picker-search aria-label="Search people">
                </div>
                <div class="people-picker-list">
                    ${raw(people.map((person) => html`
                        <label class="people-picker-row" data-name="${person.name.toLowerCase()}">
                            <input type="checkbox" value="${person.id}">
                            ${raw(T.avatar(person.user, 'sm'))}
                            <span>${person.name}</span>
                        </label>`).join('') || html`<p class="sticker-empty">Nobody to add yet. Start a chat first.</p>`)}
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-picker-cancel>Cancel</button>
                    ${raw(chooseType
                        ? html`<button type="submit" class="btn btn-primary" value="audio" disabled>${raw(icon('phone'))} Voice</button><button type="submit" class="btn btn-primary" value="video" disabled>${raw(icon('video'))} Video</button>`
                        : html`<button type="submit" class="btn btn-primary" value="audio" disabled>${submitLabel}</button>`)}
                </div>
            </form>
        `;

        const form = overlay.querySelector('form');
        const hint = overlay.querySelector('[data-picker-hint]');
        const boxes = () => [...overlay.querySelectorAll('.people-picker-row input')];
        const update = () => {
            const chosen = boxes().filter((box) => box.checked).length;
            boxes().forEach((box) => {
                box.disabled = !box.checked && chosen >= max;
            });
            form.querySelectorAll('[type="submit"]').forEach((button) => {
                button.disabled = chosen === 0;
            });
            hint.textContent = max === 1 ? 'Choose one person.' : `${chosen} of ${max} chosen`;
        };

        const close = (value) => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
            resolve(value);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close(null);
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-picker-cancel]')) close(null);
        });
        overlay.addEventListener('change', update);
        overlay.querySelector('[data-picker-search]').addEventListener('input', (event) => {
            const term = event.target.value.trim().toLowerCase();
            overlay.querySelectorAll('.people-picker-row').forEach((row) => {
                row.hidden = Boolean(term) && !row.dataset.name.includes(term);
            });
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const ids = boxes().filter((box) => box.checked).map((box) => Number(box.value));
            if (ids.length) close({ ids, type: event.submitter?.value === 'video' ? 'video' : 'audio' });
        });

        update();
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-picker-search]').focus();
    });
}
