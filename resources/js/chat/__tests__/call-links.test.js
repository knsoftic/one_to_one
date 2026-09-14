// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { callLinkMessage, CallLinks } from '../call-links';

const link = (overrides = {}) => ({
    token: 'abcdefghijklmnopqrstuvwx',
    valid: true,
    type: 'video',
    url: 'https://chat.example.com/call/abcdefghijklmnopqrstuvwx',
    owner: { id: 7, name: 'Ayesha' },
    is_mine: false,
    ...overrides,
});

function chat(overrides = {}) {
    return {
        config: { appName: 'One2One', routes: { chat: '/chat' }, callLink: null },
        api: {
            has: () => true,
            callLinks: vi.fn(async () => ({ data: [link()] })),
            createCallLink: vi.fn(async (type) => link({ token: 'newlinknewlinknewlink1', type, url: 'https://chat.example.com/call/newlinknewlinknewlink1' })),
            deleteCallLink: vi.fn(async () => ({ deleted: true })),
        },
        calls: { group: { joinLink: vi.fn() } },
        decorate: (user) => user,
        ...overrides,
    };
}

const click = (selector) => document.querySelector(selector).click();
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

afterEach(() => {
    document.body.innerHTML = '';
});

describe('K7 call links', () => {
    it('writes the message sent with a link', () => {
        expect(callLinkMessage(link(), 'One2One')).toBe('Join my video call on One2One: https://chat.example.com/call/abcdefghijklmnopqrstuvwx');
        expect(callLinkMessage(link({ type: 'audio' }))).toContain('voice call');
    });

    it('offers to join the call of an opened link', async () => {
        const app = chat();
        const links = new CallLinks(app);
        history.replaceState(null, '', '/call/abcdefghijklmnopqrstuvwx');

        const offer = links.offerToJoin(link());
        await flush();
        expect(document.querySelector('.modal-title').textContent).toBe("Join Ayesha's video call?");
        expect(location.pathname).toBe('/chat');

        click('[data-modal-value="join"]');
        await offer;
        expect(app.calls.group.joinLink).toHaveBeenCalledWith('abcdefghijklmnopqrstuvwx', 'video');
    });

    it('says when a link no longer works', async () => {
        const app = chat();
        const links = new CallLinks(app);
        await links.offerToJoin({ token: 'x', valid: false });

        expect(document.querySelector('.modal')).toBeNull();
        expect(document.body.textContent).toContain('This call link is no longer valid.');
        expect(app.calls.group.joinLink).not.toHaveBeenCalled();
    });

    it('creates, copies and deletes links', async () => {
        const app = chat();
        const links = new CallLinks(app);
        const writeText = vi.fn(async () => {});
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        await links.open();
        expect(document.querySelectorAll('.call-link-row')).toHaveLength(1);

        click('[data-link-create="audio"]');
        await vi.waitFor(() => expect(document.querySelectorAll('.call-link-row')).toHaveLength(2));
        expect(app.api.createCallLink).toHaveBeenCalledWith('audio');
        expect(writeText).toHaveBeenCalledWith('https://chat.example.com/call/newlinknewlinknewlink1');

        click('[data-link="abcdefghijklmnopqrstuvwx"] [data-link-delete]');
        await flush();
        click('.modal:last-of-type [data-modal-value="delete"]');
        await vi.waitFor(() => expect(document.querySelectorAll('.call-link-row')).toHaveLength(1));
        expect(app.api.deleteCallLink).toHaveBeenCalledWith('abcdefghijklmnopqrstuvwx');
    });
});
