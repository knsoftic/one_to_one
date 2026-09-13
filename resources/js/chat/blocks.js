import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

/**
 * Block / unblock the other participant of the open conversation.
 * Blocked users cannot send new messages; existing messages stay visible.
 */
export class BlockManager {
    constructor(chat) {
        this.chat = chat;

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            const participant = conversation?.participant;
            if (!participant || !chat.api.has('block')) return;

            items.push(
                conversation.blocked_by_me
                    ? html`<button type="button" class="dropdown-item" data-action="unblock" role="menuitem">${raw(icon('undo-2'))} Unblock ${participant.name}</button>`
                    : html`<button type="button" class="dropdown-item is-danger" data-action="block" role="menuitem">${raw(icon('ban'))} Block ${participant.name}</button>`,
            );
        });

        document.addEventListener('chat:action', (event) => {
            const { action } = event.detail;
            if (action === 'block') this.block();
            if (action === 'unblock') this.unblock();
        });

        chat.onBlockChanged = (payload) => this.onChanged(payload);
    }

    async block() {
        const conversation = this.chat.activeConversation();
        const user = conversation?.participant;
        if (!user) return;

        const confirmed = await confirmDialog({
            title: `Block ${user.name}?`,
            message: `${user.name} won't be able to send you new messages. Your existing conversation stays visible, and you can unblock them at any time.`,
            icon: 'ban',
            actions: [{ label: 'Block', value: 'block', variant: 'danger' }],
        });
        if (!confirmed) return;

        try {
            await this.chat.api.block(user.id);
            this.apply(conversation.id, { blocked_by_me: true });
            toast.success(`${user.name} has been blocked.`);
        } catch (error) {
            toast.error(errorMessage(error, 'Could not block this user.'));
        }
    }

    async unblock() {
        const conversation = this.chat.activeConversation();
        const user = conversation?.participant;
        if (!user) return;

        try {
            await this.chat.api.unblock(user.id);
            this.apply(conversation.id, { blocked_by_me: false });
            toast.success(`${user.name} has been unblocked.`);
        } catch (error) {
            toast.error(errorMessage(error, 'Could not unblock this user.'));
        }
    }

    apply(conversationId, patch) {
        const conversation = this.chat.upsertConversation({ id: conversationId, ...patch });

        if (this.chat.active?.id === conversationId) {
            this.chat.stopTyping();
            this.chat.renderHeader(conversation);
            this.chat.updateComposerState(conversation);
        }

        // Presence visibility may change: refresh from the server.
        this.chat.refreshConversation(conversationId).then(() => this.chat.refreshPresenceViews());
    }

    /** Real-time: the other user blocked/unblocked me, or I did on another device. */
    onChanged({ blocker_id: blockerId, blocked_id: blockedId, blocked, conversation_id: conversationId }) {
        const me = this.chat.me.id;
        const otherId = blockerId === me ? blockedId : blockerId;

        const conversation = conversationId
            ? this.chat.conversations.get(Number(conversationId))
            : [...this.chat.conversations.values()].find((c) => c.participant?.id === otherId);

        if (!conversation) return;

        const patch = blockerId === me ? { blocked_by_me: blocked } : { blocked_me: blocked };
        if (blockerId !== me && blocked) this.chat.setTyping(conversation.id, false);

        this.apply(conversation.id, patch);
    }
}
