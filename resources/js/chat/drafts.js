const MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;
const MAX_DRAFTS = 100;

/**
 * Unsent text per chat, kept on this device (localStorage), like WhatsApp drafts.
 */
export class DraftStore {
    /**
     * @param {string|number} userId  drafts are separate per account
     * @param {Storage|null} storage
     */
    constructor(userId, storage = safeLocalStorage()) {
        this.key = `chat:drafts:${userId}`;
        this.storage = storage;
        this.drafts = this.read();
    }

    get(conversationId) {
        return this.drafts[conversationId]?.text ?? '';
    }

    /** Save (or with empty text remove) the draft of a chat. Returns true when something changed. */
    set(conversationId, text) {
        const value = String(text ?? '');
        const current = this.get(conversationId);

        if (!value.trim()) {
            if (!(conversationId in this.drafts)) return false;
            delete this.drafts[conversationId];
        } else {
            if (value === current) return false;
            this.drafts[conversationId] = { text: value, at: Date.now() };
        }

        this.write();
        return true;
    }

    clear(conversationId) {
        return this.set(conversationId, '');
    }

    read() {
        try {
            const parsed = JSON.parse(this.storage?.getItem(this.key) || '{}');
            const now = Date.now();
            return Object.fromEntries(
                Object.entries(parsed && typeof parsed === 'object' ? parsed : {}).filter(
                    ([, draft]) => typeof draft?.text === 'string' && now - Number(draft.at) < MAX_AGE_MS,
                ),
            );
        } catch {
            return {};
        }
    }

    write() {
        // Keep the most recent drafts only.
        const entries = Object.entries(this.drafts).sort(([, a], [, b]) => b.at - a.at).slice(0, MAX_DRAFTS);
        this.drafts = Object.fromEntries(entries);

        try {
            this.storage?.setItem(this.key, JSON.stringify(this.drafts));
        } catch {
            /* storage full or unavailable: drafts live for this page only */
        }
    }
}

function safeLocalStorage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}
