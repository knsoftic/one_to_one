// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { MessageActions } from '../actions';
import * as T from '../templates';

T.setTemplateContext({ meId: 2, nameOf: (id) => ({ 3: 'Sara' })[id] ?? '' });

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G7 reply privately', () => {
    it('quotes the group message with the group name and opens the group from the quote', () => {
        const el = document.createElement('div');
        el.innerHTML = T.messageBubble({
            id: 50,
            conversation_id: 12,
            type: 'text',
            body: 'Me, but keep it secret',
            sender_id: 2,
            is_mine: true,
            created_at: new Date().toISOString(),
            reply_to: { id: 41, sender_id: 3, conversation_id: 9, group_name: 'Family', type: 'text', preview: 'Who is bringing the cake?', is_deleted: false },
        });
        const quote = el.querySelector('.reply-quote');
        expect(quote.querySelector('.reply-quote-author').textContent).toBe('Sara · Family');
        expect(quote.dataset.jumpConversation).toBe('9');

        el.innerHTML = T.messageBubble({ id: 51, conversation_id: 9, type: 'text', body: 'ok', sender_id: 2, is_mine: true, created_at: new Date().toISOString(), reply_to: { id: 41, sender_id: 3, conversation_id: 9, type: 'text', preview: 'x' } });
        expect(el.querySelector('.reply-quote').dataset.jumpConversation).toBe('');
    });

    it('offers "Reply privately" on others\' group messages and replies in the chat with the writer', async () => {
        const composerExtras = document.createElement('div');
        composerExtras.innerHTML = '<div data-composer-context></div>';
        const messageList = document.createElement('div');
        const composerInput = document.createElement('textarea');
        document.body.append(composerExtras, messageList, composerInput);

        const group = { id: 9, type: 'group', group: { name: 'Family', can_send: true, is_member: true } };
        const direct = { id: 12, type: 'direct', participant: { id: 3, name: 'Sara' } };
        let active = group;
        const chat = {
            me: { id: 2 },
            config: { limits: {} },
            api: { has: () => true },
            el: { messageList, composerExtras, composerInput, messages: document.createElement('div'), composerForm: document.createElement('form') },
            activeConversation: () => active,
            participantOf: (c) => c.participant,
            displayName: (id, name) => name,
            users: new Map(),
            startConversationWith: vi.fn((userId) => {
                active = direct;
                document.dispatchEvent(new CustomEvent('chat:opened', { detail: { conversation: direct } }));
            }),
            reactions: null,
            pins: { isPinned: () => false },
            autosize: () => {},
            updateSendState: () => {},
        };
        const actions = new MessageActions(chat);
        actions.useSheet = () => false;
        const message = { id: 41, conversation_id: 9, sender_id: 3, is_mine: false, type: 'text', body: 'Who is bringing the cake?', created_at: new Date().toISOString() };

        messageList.innerHTML = `<div data-message-id="41"><div class="message-bubble"></div></div>`;
        actions.messageFor = () => message;
        actions.openMenu(messageList.firstElementChild, messageList.querySelector('.message-bubble'));
        const labels = [...document.querySelectorAll('.message-menu [data-message-action]')].map((b) => b.dataset.messageAction);
        expect(labels).toContain('reply-private');

        await actions.handle('reply-private', message);
        await vi.waitFor(() => expect(actions.mode?.type).toBe('reply'));
        expect(chat.startConversationWith).toHaveBeenCalledWith(3);
        expect(actions.mode.message.group_name).toBe('Family');
        expect(composerExtras.textContent).toContain('Replying to Sara · Family');

        const detail = { payload: {} };
        document.dispatchEvent(new CustomEvent('chat:compose-payload', { detail }));
        expect(detail.payload.reply_to_id).toBe(41);
        expect(detail.payload.reply_preview).toMatchObject({ conversation_id: 9, group_name: 'Family' });
    });
});
