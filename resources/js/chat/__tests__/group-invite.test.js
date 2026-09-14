// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { GroupInvites, qrSvg } from '../group-invite';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function chat(overrides = {}) {
    return {
        config: { appName: 'One2One', routes: { chat: '/chat' } },
        api: {
            has: () => true,
            groupInvite: vi.fn(async () => ({ url: 'https://chat.example.com/join/AbCdEfGhIjKlMnOpQrStUv', token: 'AbCdEfGhIjKlMnOpQrStUv' })),
            resetGroupInvite: vi.fn(async () => ({ url: 'https://chat.example.com/join/NewNewNewNewNewNewNewNe', token: 'NewNewNewNewNewNewNewNe' })),
            joinGroup: vi.fn(async () => ({ id: 9, type: 'group', group: { name: 'Cricket Club' } })),
        },
        upsertConversation: vi.fn(),
        openConversation: vi.fn(),
        ...overrides,
    };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('G3 group invite link', () => {
    it('draws a QR code for the link', () => {
        const svg = qrSvg('https://chat.example.com/join/AbCdEfGhIjKlMnOpQrStUv');
        expect(svg.startsWith('<svg')).toBe(true);
        expect(svg).toContain('viewBox');
        // A version 3+ code for a link this long: many dark modules.
        expect(svg.length).toBeGreaterThan(1000);
    });

    it('shows the link with a QR code and resets it', async () => {
        const app = chat();
        const invites = new GroupInvites(app);

        await invites.open({ id: 9, group: { name: 'Cricket Club' } });
        expect(document.querySelector('[data-invite-url]').textContent).toBe('https://chat.example.com/join/AbCdEfGhIjKlMnOpQrStUv');
        expect(document.querySelector('.group-invite-qr svg')).not.toBeNull();

        document.querySelector('[data-invite-reset]').click();
        await flush();
        document.querySelector('.modal:last-of-type [data-modal-value="reset"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-invite-url]').textContent).toContain('NewNewNew'));
        expect(app.api.resetGroupInvite).toHaveBeenCalledWith(9);
    });

    it('offers to join from a link and opens the group', async () => {
        const app = chat();
        const invites = new GroupInvites(app);
        history.replaceState(null, '', '/join/AbCdEfGhIjKlMnOpQrStUv');

        await invites.offerToJoin({ valid: true, token: 'AbCdEfGhIjKlMnOpQrStUv', name: 'Cricket Club', member_count: 12, description: 'Sunday matches', is_member: false, conversation_id: 9, initials: 'CC', avatar_hue: 10 });
        expect(location.pathname).toBe('/chat');
        expect(document.querySelector('#group-join-title').textContent).toBe('Cricket Club');
        expect(document.querySelector('.group-join-count').textContent).toBe('Group · 12 members');

        document.querySelector('[data-join]').click();
        await vi.waitFor(() => expect(app.openConversation).toHaveBeenCalledWith(9));
        expect(app.api.joinGroup).toHaveBeenCalledWith('AbCdEfGhIjKlMnOpQrStUv');
    });

    it('opens the group straight away for members and explains dead links', async () => {
        const app = chat();
        const invites = new GroupInvites(app);

        await invites.offerToJoin({ valid: true, is_member: true, conversation_id: 9 });
        expect(app.openConversation).toHaveBeenCalledWith(9);

        await invites.offerToJoin({ valid: false, token: 'x' });
        expect(document.body.textContent).toContain('This invite link is no longer valid');
    });
});
