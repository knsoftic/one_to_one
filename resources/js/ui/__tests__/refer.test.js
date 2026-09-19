// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { get: vi.fn() } }));
vi.mock('../../lib/toast', () => ({ toast: vi.fn() }));

import axios from '../../bootstrap';
import { toast } from '../../lib/toast';
import { initRefer } from '../refer';

const summary = {
    code: 'ABCD2345', link: 'https://chat.test/r/ABCD2345', reward: 50, welcome: 10, invited: 3, rewarded: 2, pending: 1, coins_earned: 100,
    list: [
        { id: 1, name: 'Sara <b>', status: 'rewarded', coins: 50, date: '2026-09-10T10:00:00Z' },
        { id: 2, name: 'Bilal', status: 'pending', coins: 0, date: '2026-09-11T10:00:00Z' },
        { id: 3, name: 'Deleted account', status: 'void', coins: 0, date: '2026-09-12T10:00:00Z' },
    ],
};

function mount({ active = true } = {}) {
    document.body.innerHTML = `
        <section data-settings-section="refer" class="${active ? 'is-active' : ''}">
            <div class="wa-group" data-refer data-route="/settings/refer"></div>
        </section>`;
    return initRefer({ routes: { referral: '/settings/refer' }, user: { username: 'ali' }, app: { name: 'One2One' } });
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    delete navigator.share;
});

describe('Refer & earn', () => {
    it('shows the code, the link, the reward text, counters and the list', async () => {
        axios.get.mockResolvedValue({ data: summary });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-refer-code]')).not.toBeNull());

        expect(document.querySelector('[data-refer-code]').textContent).toBe('ABCD2345');
        expect(document.querySelector('[data-refer-link]').textContent).toBe('https://chat.test/r/ABCD2345');
        expect(document.querySelector('.refer-hero-text').textContent).toContain('50 coins');
        expect(document.querySelector('.refer-hero-text').textContent).toContain('10 welcome coins');
        expect(document.querySelector('[data-refer-invited]').textContent).toBe('3');
        expect(document.querySelector('[data-refer-rewarded]').textContent).toBe('2');
        expect(document.querySelector('[data-refer-earned]').textContent).toBe('100');
        expect(document.querySelectorAll('[data-refer-row]')).toHaveLength(3);
        expect(document.querySelector('[data-refer-row="1"] .wa-row-title').textContent).toBe('Sara <b>');
        expect(document.querySelector('[data-refer-row="1"] .refer-status').textContent).toBe('Rewarded');
        expect(document.querySelector('[data-refer-row="2"] .refer-status').classList.contains('is-pending')).toBe(true);
        expect(document.querySelector('[data-refer-row="3"] .refer-status').textContent).toBe('Not counted');
    });

    it('waits for the section to open', async () => {
        axios.get.mockResolvedValue({ data: summary });
        mount({ active: false });
        expect(axios.get).not.toHaveBeenCalled();
        document.dispatchEvent(new CustomEvent('settings:section', { detail: { name: 'refer' } }));
        await vi.waitFor(() => expect(axios.get).toHaveBeenCalledWith('/settings/refer'));
    });

    it('shares through navigator.share when the phone has it', async () => {
        axios.get.mockResolvedValue({ data: summary });
        navigator.share = vi.fn(async () => {});
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-refer-share]')).not.toBeNull());

        expect(document.querySelector('[data-refer-share]').textContent).toContain('Share');
        document.querySelector('[data-refer-share]').click();
        await vi.waitFor(() => expect(navigator.share).toHaveBeenCalled());
        const [payload] = navigator.share.mock.calls[0];
        expect(payload.url).toBe('https://chat.test/r/ABCD2345');
        expect(payload.text).toContain('https://chat.test/r/ABCD2345');
        expect(payload.text).toContain('@ali');
    });

    it('copies the invite when there is no share sheet, and copies the code', async () => {
        axios.get.mockResolvedValue({ data: summary });
        const writeText = vi.fn(async () => {});
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-refer-share]')).not.toBeNull());

        expect(document.querySelector('[data-refer-share]').textContent).toContain('Copy link');
        document.querySelector('[data-refer-share]').click();
        await vi.waitFor(() => expect(writeText).toHaveBeenCalled());
        expect(writeText.mock.calls[0][0]).toContain('https://chat.test/r/ABCD2345');
        expect(toast).toHaveBeenCalledWith(expect.stringContaining('copied'), expect.anything());

        document.querySelector('[data-refer-copy="code"]').click();
        await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith('ABCD2345'));
    });

    it('shows an error with a retry when the request fails', async () => {
        axios.get.mockRejectedValueOnce(new Error('nope')).mockResolvedValueOnce({ data: summary });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-refer-retry]')).not.toBeNull());
        document.querySelector('[data-refer-retry]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-refer-code]')).not.toBeNull());
    });
});
