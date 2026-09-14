// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));

import axios from '../../bootstrap';
import { initQrLogin } from '../../auth/qr-login';
import { LinkedDevices, normaliseCode, tokenFromQr } from '../linked-devices';
import { ReportUser } from '../report';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

afterEach(() => {
    document.body.innerHTML = '';
    vi.useRealTimers();
});

const clickModal = async (value) => {
    await vi.waitFor(() => expect(document.querySelector(`[data-modal-value="${value}"]`)).not.toBeNull());
    document.querySelector(`[data-modal-value="${value}"]`).click();
};

describe('P6 report dialog', () => {
    it('needs a reason, sends the report and blocks', async () => {
        const conversation = { id: 5, type: 'direct', participant: { id: 2, name: 'Cheap Loans' } };
        const chat = {
            api: { has: () => true, reportUser: vi.fn(async () => ({ id: 1, blocked: true })) },
            activeConversation: () => conversation,
            participantOf: () => ({ name: 'Loans Guy' }),
            blocks: { apply: vi.fn() },
        };
        const reports = new ReportUser(chat);

        const items = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation, items } }));
        expect(items.join('')).toContain('data-action="report-user"');

        reports.open(conversation);
        const form = document.querySelector('.report-form');
        expect(form.querySelector('.modal-title').textContent).toBe('Report Loans Guy?');
        form.dispatchEvent(new Event('submit', { cancelable: true }));
        expect(document.querySelector('[data-report-error]').textContent).toBe('Choose why you are reporting.');

        form.querySelector('input[value="spam"]').checked = true;
        form.elements.namedItem('details').value = ' Loan ads ';
        form.dispatchEvent(new Event('submit', { cancelable: true }));

        await vi.waitFor(() => expect(chat.api.reportUser).toHaveBeenCalledWith(2, { reason: 'spam', details: 'Loan ads', conversation_id: 5, block: true }));
        await vi.waitFor(() => expect(chat.blocks.apply).toHaveBeenCalledWith(5, { blocked_by_me: true }));
        expect(document.querySelector('.report-form')).toBeNull();
    });
});

describe('P10 linked devices', () => {
    it('reads login QR codes and typed codes', () => {
        const token = 'A'.repeat(20) + 'b'.repeat(20);
        expect(tokenFromQr(`https://chat.hunario.com/link-device/${token}`)).toBe(token);
        expect(tokenFromQr(`https://chat.hunario.com/link-device/${token}?x=1`)).toBe(token);
        expect(tokenFromQr('https://chat.hunario.com/join/abc')).toBeNull();
        expect(tokenFromQr(`https://evil.test/link-device/${token}extra`)).toBeNull();
        expect(normaliseCode(' abcd-ef9h ')).toBe('ABCDEF9H');
    });

    it('lists sessions, signs one out and links a device after confirming', async () => {
        const chat = {
            config: { appName: 'One2One', routes: { chat: '/chat' } },
            api: {
                has: () => true,
                sessions: vi.fn(async () => ({ data: [
                    { key: 'k1', device: 'Chrome on Windows', mobile: false, ip: '39.45.10.1', last_active: new Date().toISOString(), current: true },
                    { key: 'k2', device: 'One2One app on Android', mobile: true, ip: '39.45.10.2', last_active: new Date(Date.now() - 3600e3).toISOString(), current: false },
                ] })),
                logoutSession: vi.fn(async () => ({ signed_out: true })),
                logoutOtherSessions: vi.fn(async () => ({ signed_out: 1, message: '1 other device has been signed out.' })),
                lookupLink: vi.fn(async () => ({ device: 'Firefox on Mac', ip: '8.8.8.8' })),
                approveLink: vi.fn(async () => ({ approved: true })),
            },
        };
        const devices = new LinkedDevices(chat);

        await devices.open();
        expect([...document.querySelectorAll('.linked-row-name')].map((el) => el.textContent.trim())).toEqual(['Chrome on Windows This device', 'One2One app on Android']);
        document.querySelector('[data-session-logout="k2"]').click();
        await vi.waitFor(() => expect(chat.api.logoutSession).toHaveBeenCalledWith('k2'));

        const linking = devices.confirmLink({ code: 'ABCDEFGH' });
        await vi.waitFor(() => expect(document.querySelector('.modal-title')?.textContent).toBe('Link Firefox on Mac?'));
        await clickModal('link');
        await expect(linking).resolves.toBe(true);
        expect(chat.api.approveLink).toHaveBeenCalledWith({ code: 'ABCDEFGH' });
    });

    it('offers to link a device opened from its QR link', async () => {
        const chat = {
            config: { routes: { chat: '/chat' }, linkDevice: { token: 'T'.repeat(40) } },
            api: { has: () => true, lookupLink: vi.fn(async () => ({ device: 'Edge on Windows' })), approveLink: vi.fn() },
        };
        new LinkedDevices(chat);
        await vi.waitFor(() => expect(chat.api.lookupLink).toHaveBeenCalledWith({ token: 'T'.repeat(40) }), { timeout: 2000 });
        await clickModal('cancel').catch(() => {});
        expect(chat.api.approveLink).not.toHaveBeenCalled();
    });
});

describe('P10 QR login page', () => {
    it('shows the QR code and signs in once the phone approves', async () => {
        document.body.innerHTML = `<aside data-qr-login data-create-url="/login/qr" data-status-url="/login/qr/__TOKEN__/status"><div data-qr-box></div><strong data-qr-code></strong></aside>`;
        const assign = vi.fn();
        Object.defineProperty(window, 'location', { value: { assign }, configurable: true });

        axios.post
            .mockResolvedValueOnce({ data: { token: 'tok', secret: 's'.repeat(40), code: 'ABCD-EFGH', url: 'https://chat.test/link-device/tok' } })
            .mockResolvedValueOnce({ data: { state: 'waiting' } })
            .mockResolvedValueOnce({ data: { state: 'approved', redirect: '/chat' } });

        initQrLogin(document.querySelector('[data-qr-login]'), { poll: 5 });

        await vi.waitFor(() => expect(document.querySelector('[data-qr-box] svg')).not.toBeNull());
        expect(document.querySelector('[data-qr-code]').textContent).toBe('ABCD-EFGH');
        await vi.waitFor(() => expect(assign).toHaveBeenCalledWith('/chat'));
        expect(axios.post).toHaveBeenCalledWith('/login/qr/tok/status', { secret: 's'.repeat(40) });
    });

    it('offers a new code when it expires', async () => {
        document.body.innerHTML = `<aside data-qr-login data-create-url="/login/qr" data-status-url="/login/qr/__TOKEN__/status"><div data-qr-box></div><strong data-qr-code></strong></aside>`;
        axios.post.mockReset();
        axios.post
            .mockResolvedValueOnce({ data: { token: 'tok', secret: 's'.repeat(40), code: 'ABCD-EFGH', url: 'https://chat.test/link-device/tok' } })
            .mockResolvedValueOnce({ data: { state: 'expired' } })
            .mockResolvedValueOnce({ data: { token: 'tok2', secret: 't'.repeat(40), code: 'JKLM-NPQR', url: 'https://chat.test/link-device/tok2' } })
            .mockResolvedValue({ data: { state: 'waiting' } });

        const login = initQrLogin(document.querySelector('[data-qr-login]'), { poll: 5 });
        await vi.waitFor(() => expect(document.querySelector('[data-qr-refresh]')).not.toBeNull());
        document.querySelector('[data-qr-refresh]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-qr-code]').textContent).toBe('JKLM-NPQR'));
        login.stop();
    });
});
