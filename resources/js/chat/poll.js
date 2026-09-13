import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * M20 — Polls: create (question, 2–12 options, one or several answers) and vote.
 */

export const MAX_OPTIONS = 12;

/** Selection after tapping an option. */
export function nextSelection(current, optionId, multiple) {
    const chosen = new Set(current);
    if (chosen.has(optionId)) {
        chosen.delete(optionId);
    } else {
        if (!multiple) chosen.clear();
        chosen.add(optionId);
    }
    return [...chosen].sort((a, b) => a - b);
}

/** The poll as it looks after a user's answers change (for instant feedback). */
export function applyVote(poll, userId, selection) {
    const uid = Number(userId);
    const options = poll.options.map((option) => {
        const voters = option.voter_ids.filter((id) => Number(id) !== uid);
        if (selection.includes(option.id)) voters.push(uid);
        return { ...option, voter_ids: voters, count: voters.length };
    });
    const voters = new Set(options.flatMap((option) => option.voter_ids.map(Number)));

    return { ...poll, options, total_voters: voters.size };
}

/** Options typed in the creator: trimmed, non-empty, no duplicates (case-insensitive). */
export function cleanOptions(values) {
    const seen = new Set();
    return values
        .map((value) => String(value ?? '').replace(/\s+/g, ' ').trim())
        .filter((value) => {
            const key = value.toLowerCase();
            if (!value || seen.has(key)) return false;
            seen.add(key);
            return true;
        })
        .slice(0, MAX_OPTIONS);
}

export class Polls {
    constructor(chat) {
        this.chat = chat;
        this.saving = new Set();

        chat.el.messageList.addEventListener('click', (event) => {
            const option = event.target.closest('[data-poll-option]');
            if (option) this.vote(Number(option.closest('[data-poll]').dataset.poll), Number(option.dataset.pollOption));
        });
    }

    async vote(messageId, optionId) {
        const message = this.chat.active?.byId.get(String(messageId));
        if (!message?.poll || this.saving.has(messageId)) return;

        const me = this.chat.me.id;
        const current = message.poll.options.filter((option) => option.voter_ids.map(Number).includes(Number(me))).map((option) => option.id);
        const selection = nextSelection(current, optionId, message.poll.multiple);
        const previous = message.poll;

        this.chat.updateMessage({ ...message, poll: applyVote(previous, me, selection) });
        this.saving.add(messageId);

        try {
            const result = await this.chat.api.vote(messageId, selection);
            const latest = this.chat.active?.byId.get(String(messageId));
            if (latest) this.chat.updateMessage({ ...latest, poll: result.poll });
        } catch (error) {
            const latest = this.chat.active?.byId.get(String(messageId));
            if (latest) this.chat.updateMessage({ ...latest, poll: previous });
            toast.error(errorMessage(error, 'Your vote was not saved.'));
        } finally {
            this.saving.delete(messageId);
        }
    }

    open() {
        const conversationId = this.chat.active?.id;
        if (!conversationId) return;

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal poll-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'poll-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-poll-cancel></div>
            <form class="modal-panel poll-panel" data-poll-form novalidate>
                <h2 class="modal-title" id="poll-title">Create poll</h2>
                <label class="form-label" for="poll-question">Question</label>
                <input class="form-control" id="poll-question" name="question" maxlength="255" placeholder="Ask a question" autocomplete="off" required>
                <span class="form-label">Options</span>
                <div class="poll-options" data-poll-options></div>
                <label class="poll-multiple">
                    <input type="checkbox" name="multiple" checked>
                    <span>Allow multiple answers</span>
                </label>
                <p class="poll-error" data-poll-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-poll-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">${raw(icon('send-horizontal'))} Send</button>
                </div>
            </form>
        `;

        const list = overlay.querySelector('[data-poll-options]');
        const addOption = (value = '') => {
            const index = list.children.length + 1;
            const row = document.createElement('div');
            row.className = 'poll-option-input';
            row.innerHTML = html`<input class="form-control" maxlength="100" placeholder="Option ${index}" aria-label="Option ${index}" value="${value}">`;
            list.appendChild(row);
            return row.querySelector('input');
        };
        addOption();
        addOption();

        // A new empty row appears when the last one is used (up to 12).
        list.addEventListener('input', () => {
            const inputs = [...list.querySelectorAll('input')];
            if (inputs.at(-1).value.trim() && inputs.length < MAX_OPTIONS) addOption();
        });

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-poll-cancel]')) close();
        });

        overlay.querySelector('[data-poll-form]').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = event.target;
            const question = form.question.value.replace(/\s+/g, ' ').trim();
            const options = cleanOptions([...list.querySelectorAll('input')].map((input) => input.value));
            const error = overlay.querySelector('[data-poll-error]');

            const problem = !question ? 'Type a question.' : options.length < 2 ? 'Add at least 2 different options.' : '';
            error.hidden = !problem;
            error.textContent = problem;
            if (problem) return;

            close();
            this.send(conversationId, { question, options, multiple: form.multiple.checked });
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('#poll-question').focus();
    }

    send(conversationId, poll) {
        return this.chat.sendSpecial(conversationId, {
            type: 'poll',
            fields: { poll },
            attachment: null,
            extra: {
                poll: {
                    question: poll.question,
                    multiple: poll.multiple,
                    options: poll.options.map((text, index) => ({ id: index + 1, text, count: 0, voter_ids: [] })),
                    total_voters: 0,
                },
            },
        });
    }
}
