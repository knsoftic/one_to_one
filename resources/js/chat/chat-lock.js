import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * C9 — Chat lock. Locked chats wait in a "Locked chats" folder that opens with
 * a secret code. The folder closes again when you leave it or stop using it.
 */

export const PIN_PATTERN = /^\d{4,8}$/;

/** Closed after this long without clicks or typing while the folder is open. */
const IDLE_LOCK_MS = 5 * 60 * 1000;

export function isLocked(conversation) {
    return Boolean(conversation?.settings?.locked);
}

export class ChatLock {
    constructor(chat) {
        this.chat = chat;
        const state = chat.config.chatLock ?? {};
        this.enabled = Boolean(state.enabled);
        this.unlockedUntil = state.unlockedUntil ? Date.parse(state.unlockedUntil) : 0;
        this.lastActivity = Date.now();

        if (!chat.api.has('chatLockUnlock')) return;

        ['pointerdown', 'keydown'].forEach((type) => document.addEventListener(type, () => {
            this.lastActivity = Date.now();
        }, { passive: true }));

        this.timer = setInterval(() => {
            if (this.isUnlocked() && Date.now() - this.lastActivity > IDLE_LOCK_MS) this.lockNow();
        }, 30_000);

        // Leaving an open locked chat on a phone goes back to the folder, not further.
        document.addEventListener('chat:closed', () => {
            if (this.chat.listMode === 'locked' && !this.isUnlocked()) this.chat.setListMode('chats');
        });
    }

    get available() {
        return this.chat.api.has('chatLockUnlock');
    }

    isUnlocked() {
        return this.unlockedUntil > Date.now();
    }

    /** Open "Locked chats" (asks for the code). */
    async openFolder() {
        if (await this.unlock()) this.chat.setListMode('locked');
    }

    /** Back to the chat list: the folder locks again. */
    async closeFolder() {
        this.chat.setListMode('chats');
        await this.lockNow();
    }

    /**
     * Ask for the secret code unless the folder is already open.
     *
     * @returns {Promise<boolean>} unlocked
     */
    async unlock() {
        if (this.isUnlocked()) return true;

        const pin = await this.prompt({
            title: 'Enter your secret code',
            text: 'Locked chats open with the code you created.',
            submit: 'Unlock',
            forgot: true,
            send: (values) => this.chat.api.unlockChats(values.pin),
        });
        if (!pin) return false;

        this.setState(pin);
        this.lastActivity = Date.now();
        await this.chat.loadConversations();
        return true;
    }

    async lockNow() {
        const wasUnlocked = this.isUnlocked();
        this.unlockedUntil = 0;
        if (this.chat.listMode === 'locked') this.chat.setListMode('chats');
        if (isLocked(this.chat.activeConversation())) this.chat.closeConversation();

        try {
            await this.chat.api.lockChats();
        } catch {
            /* the server unlock ends by itself */
        }
        if (wasUnlocked) await this.chat.loadConversations();
    }

    /** "Lock chat" from a chat menu; creates the secret code the first time. */
    async lockChat(conversation) {
        if (!this.enabled) {
            const state = await this.prompt({
                title: 'Create a secret code',
                text: 'Locked chats are hidden in "Locked chats" and open only with this code (4–8 digits). Notifications will not show who wrote or what.',
                submit: 'Create code',
                confirm: true,
                send: (values) => this.chat.api.setChatLockPin({ pin: values.pin, pin_confirmation: values.confirm }),
            });
            if (!state) return;
            this.setState(state);
        }

        if (this.chat.active?.id === conversation.id) this.chat.closeConversation();
        await this.chat.chatList.apply(conversation, { locked: true }, 'Chat locked. Find it in Locked chats.');
        // Creating the code opened the folder; close it unless it is in use.
        if (this.chat.listMode !== 'locked' && this.isUnlocked()) await this.lockNow();
    }

    async unlockChat(conversation) {
        if (!(await this.unlock())) return;
        await this.chat.chatList.apply(conversation, { locked: false }, 'Chat unlocked.');
    }

    setState(state) {
        this.enabled = Boolean(state.enabled);
        this.unlockedUntil = state.unlocked_until ? Date.parse(state.unlocked_until) : 0;
    }

    /** Forgotten code: remove it with the account password; locked chats return to the list. */
    async forgot() {
        const state = await this.prompt({
            title: 'Forgot your secret code?',
            text: 'Enter your account password to remove the code. Your locked chats will be unlocked and shown in the chat list.',
            submit: 'Remove code',
            password: true,
            send: (values) => this.chat.api.removeChatLockPin(values.password),
        });
        if (!state) return;

        this.setState(state);
        toast.success('Secret code removed. Your chats are unlocked.');
        if (this.chat.listMode === 'locked') this.chat.setListMode('chats');
        await this.chat.loadConversations();
    }

    /**
     * Small form dialog for the code (or the password).
     *
     * @returns {Promise<object|null>} the server's answer, or null when cancelled
     */
    prompt({ title, text, submit, confirm = false, password = false, forgot = false, send }) {
        return new Promise((resolve) => {
            const previouslyFocused = document.activeElement;
            const overlay = document.createElement('div');
            overlay.className = 'modal chat-lock-modal';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-labelledby', 'chat-lock-title');

            const codeInput = (name, label) => html`
                <label class="sr-only" for="chat-lock-${name}">${label}</label>
                <input class="form-control chat-lock-code" id="chat-lock-${name}" name="${name}" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" placeholder="${label}" required>`;

            overlay.innerHTML = html`
                <div class="modal-backdrop" data-lock-cancel></div>
                <form class="modal-panel chat-lock-panel" novalidate>
                    <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('lock-keyhole'))}</div>
                    <h2 class="modal-title" id="chat-lock-title">${title}</h2>
                    <p class="modal-text">${text}</p>
                    ${raw(password
                        ? html`<label class="sr-only" for="chat-lock-password">Account password</label><input class="form-control" id="chat-lock-password" name="password" type="password" autocomplete="current-password" placeholder="Account password" required>`
                        : codeInput('pin', 'Secret code') + (confirm ? codeInput('confirm', 'Repeat the code') : ''))}
                    <p class="poll-error" data-lock-error hidden></p>
                    ${raw(forgot ? '<button type="button" class="chat-lock-forgot" data-lock-forgot>Forgot code?</button>' : '')}
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" data-lock-cancel>Cancel</button>
                        <button type="submit" class="btn btn-primary">${submit}</button>
                    </div>
                </form>
            `;

            const close = (value) => {
                document.removeEventListener('keydown', onKey);
                overlay.remove();
                previouslyFocused?.focus?.();
                resolve(value);
            };
            const onKey = (event) => {
                if (event.key === 'Escape') close(null);
            };
            const form = overlay.querySelector('form');
            const error = overlay.querySelector('[data-lock-error]');
            const fail = (message) => {
                error.hidden = false;
                error.textContent = message;
            };

            overlay.addEventListener('click', (event) => {
                if (event.target.closest('[data-lock-cancel]')) close(null);
                if (event.target.closest('[data-lock-forgot]')) {
                    close(null);
                    this.forgot();
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const values = Object.fromEntries(new FormData(form));
                if (!password && !PIN_PATTERN.test(values.pin ?? '')) return fail('The secret code must be 4 to 8 digits.');
                if (confirm && values.pin !== values.confirm) return fail('The two codes do not match.');
                if (password && !values.password) return fail('Enter your password.');

                const button = form.querySelector('[type="submit"]');
                button.disabled = true;
                try {
                    close(await send(values));
                } catch (err) {
                    button.disabled = false;
                    form.querySelectorAll('input').forEach((input) => { input.value = ''; });
                    form.querySelector('input')?.focus();
                    fail(err?.response?.status === 429 ? 'Too many attempts. Try again in a minute.' : errorMessage(err, 'That did not work. Try again.'));
                }
            });

            document.addEventListener('keydown', onKey);
            document.body.appendChild(overlay);
            form.querySelector('input')?.focus();
        });
    }
}
