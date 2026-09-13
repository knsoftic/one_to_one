import { errorMessage } from '../lib/dom';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

const DURATIONS = [
    { label: '24 hours', value: '86400' },
    { label: '7 days', value: '604800' },
    { label: '30 days', value: '2592000' },
];

/**
 * Pinned messages bar under the chat header (up to three; tapping jumps to
 * the shown pin and moves on to the next one, like WhatsApp).
 */
export class PinnedMessages {
    constructor(chat) {
        this.chat = chat;
        this.pins = [];
        this.index = 0;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            bar: q('[data-pinned-bar]'),
            label: q('[data-pinned-label]'),
            text: q('[data-pinned-text]'),
            dots: q('[data-pinned-dots]'),
        };

        if (!this.el.bar || !chat.api.has('messagePin')) return;

        this.el.bar.addEventListener('click', () => this.jump());

        document.addEventListener('chat:header', (event) => {
            const { conversation } = event.detail;
            if (conversation?.id !== this.chat.active?.id || !Array.isArray(conversation.pinned_messages)) return;
            this.set(conversation.pinned_messages);
        });
        document.addEventListener('chat:opened', (event) => this.set(event.detail.conversation?.pinned_messages ?? []));
        document.addEventListener('chat:closed', () => this.set([]));

        // Pins can expire while the chat is open.
        setInterval(() => {
            const now = Date.now();
            if (this.pins.some((pin) => Date.parse(pin.expires_at) <= now)) {
                this.set(this.pins.filter((pin) => Date.parse(pin.expires_at) > now));
            }
        }, 60_000);
    }

    /** A pinned message was deleted: drop it from the bar. */
    forget(messageId) {
        if (this.pins.some((pin) => pin.message_id === Number(messageId))) {
            this.set(this.pins.filter((pin) => pin.message_id !== Number(messageId)));
        }
    }

    isPinned(message) {
        return this.pins.some((pin) => pin.message_id === message.id);
    }

    set(pins) {
        const currentId = this.pins[this.index]?.message_id;
        this.pins = pins ?? [];
        const kept = this.pins.findIndex((pin) => pin.message_id === currentId);
        this.index = kept === -1 ? 0 : kept;
        this.render();
    }

    render() {
        const { bar, label, text, dots } = this.el;
        const pin = this.pins[this.index];
        bar.hidden = !pin;
        this.chat.el.panel.classList.toggle('has-pins', Boolean(pin));
        if (!pin) return;

        const total = this.pins.length;
        label.textContent = total > 1 ? `Pinned message ${this.index + 1} of ${total}` : 'Pinned message';
        text.textContent = pin.preview || 'Message';
        dots.innerHTML = total > 1 ? this.pins.map((_, i) => `<i class="${i === this.index ? 'is-current' : ''}"></i>`).join('') : '';
        bar.setAttribute('aria-label', `${label.textContent}: ${text.textContent}. Show message`);
    }

    async jump() {
        const pin = this.pins[this.index];
        if (!pin) return;
        await this.chat.jumpToMessage(pin.message_id, { deep: true });
        if (this.pins.length > 1) {
            this.index = (this.index + 1) % this.pins.length;
            this.render();
        }
    }

    async pin(message) {
        const choice = await confirmDialog({
            title: 'Choose how long your pin lasts',
            message: 'You can unpin at any time. Both of you see pinned messages at the top of the chat.',
            icon: 'pin',
            tone: 'primary',
            actions: DURATIONS.map((duration, i) => ({ ...duration, variant: i === 1 ? 'primary' : 'secondary' })),
        });
        if (!choice) return;

        try {
            const data = await this.chat.api.pin(message.id, Number(choice));
            this.apply(data);
            toast.success('Message pinned.', { timeout: 2000 });
        } catch (error) {
            toast.error(errorMessage(error, 'Could not pin the message.'));
        }
    }

    async unpin(message) {
        try {
            const data = await this.chat.api.unpin(message.id);
            this.apply(data);
            toast.success('Message unpinned.', { timeout: 2000 });
        } catch (error) {
            toast.error(errorMessage(error, 'Could not unpin the message.'));
        }
    }

    apply({ conversation_id: conversationId, pinned_messages: pins }) {
        const conversation = this.chat.conversations.get(Number(conversationId));
        if (conversation) conversation.pinned_messages = pins;
        if (this.chat.active?.id === Number(conversationId)) {
            this.index = 0;
            this.set(pins);
        }
    }
}
