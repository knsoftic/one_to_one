// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import { ShareReceiver, describeShare, readNativeFile } from '../share-receiver';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

function fakeChat() {
    const conversations = [
        { id: 1, type: 'direct', participant: { id: 2, name: 'Ayesha Khan', initials: 'AK' } },
        { id: 2, type: 'direct', participant: { id: 3, name: 'Bilal Ahmed', initials: 'BA' } },
    ];
    return {
        active: { id: 1, loaded: true },
        el: { composerInput: document.createElement('textarea') },
        api: { startConversation: vi.fn(), forwardMessage: vi.fn() },
        sortedConversations: () => conversations,
        participantOf: (c) => c.participant,
        contactsPanel: { contacts: [] },
        openConversation: vi.fn(async () => {}),
        attachments: { add: vi.fn(async () => {}), classify: (file) => (file.type.startsWith('image/') ? 'image' : null) },
        autosize: vi.fn(),
        updateSendState: vi.fn(),
        sendText: vi.fn(),
        sendFile: vi.fn(),
    };
}

const photo = () => new File([new Uint8Array([1, 2, 3])], 'beach.jpg', { type: 'image/jpeg' });

afterEach(() => {
    document.body.innerHTML = '';
    history.replaceState(null, '', '/chat');
});

describe('X2 share into the app', () => {
    it('describes what was shared', () => {
        expect(describeShare({ text: '  Look at   this ', files: [photo(), photo(), new File([''], 'a.pdf', { type: 'application/pdf' })] })).toBe('2 photos · 1 file · Look at this');
        expect(describeShare({})).toBe('Shared content');
    });

    it('rebuilds a file from base64 pieces', async () => {
        const bytes = Uint8Array.from({ length: 10 }, (_, i) => i);
        const NativeApp = { readSharedFile: vi.fn(async ({ offset, length }) => ({ data: btoa(String.fromCharCode(...bytes.slice(offset, offset + length))) })) };
        const file = await readNativeFile(NativeApp, { index: 0, size: 10, name: 'x.bin', mime: 'application/zip' }, 4);
        expect(NativeApp.readSharedFile).toHaveBeenCalledTimes(3);
        expect(new Uint8Array(await file.arrayBuffer())).toEqual(bytes);
        expect(file.type).toBe('application/zip');
    });

    it('one chat: opens it with the files and text ready to send', async () => {
        const chat = fakeChat();
        const NativeApp = {
            addListener: vi.fn(),
            getSharedContent: vi.fn(async () => ({ id: 's1', text: 'Holiday pics', files: [{ index: 0, name: 'beach.jpg', mime: 'image/jpeg', size: 3, skipped: false }] })),
            readSharedFile: vi.fn(async () => ({ data: btoa('abc') })),
            clearSharedContent: vi.fn(async () => {}),
        };
        const receiver = new ShareReceiver(chat);
        await receiver.listenNative(NativeApp);

        expect(document.querySelector('#forward-title').textContent).toBe('Send to…');
        expect(document.querySelector('.forward-preview').textContent).toContain('1 photo · Holiday pics');

        document.querySelector('[data-forward-key="c2"]').click();
        document.querySelector('[data-forward-send]').click();
        await vi.waitFor(() => expect(chat.attachments.add).toHaveBeenCalled());

        expect(chat.openConversation).toHaveBeenCalledWith(2);
        expect(chat.attachments.add.mock.calls[0][0][0].name).toBe('beach.jpg');
        expect(chat.el.composerInput.value).toBe('Holiday pics');
        expect(NativeApp.clearSharedContent).toHaveBeenCalled();
        expect(NativeApp.addListener).toHaveBeenCalledWith('shareReceived', expect.any(Function));
    });

    it('several chats: sends right away; web share links come from the address', async () => {
        history.replaceState(null, '', '/chat?share_title=News&share_url=https%3A%2F%2Fexample.com%2Fa&source=pwa');
        const chat = fakeChat();
        new ShareReceiver(chat);
        expect(window.location.search).toBe('?source=pwa');

        document.dispatchEvent(new CustomEvent('chat:ready'));
        expect(document.querySelector('.forward-preview').textContent).toContain('News https://example.com/a');

        document.querySelector('[data-forward-key="c1"]').click();
        document.querySelector('[data-forward-key="c2"]').click();
        document.querySelector('[data-forward-send]').click();
        await vi.waitFor(() => expect(chat.sendText).toHaveBeenCalledTimes(2));
        expect(chat.sendText).toHaveBeenCalledWith(1, 'News\nhttps://example.com/a');
        expect(chat.openConversation).not.toHaveBeenCalled();
    });
});
