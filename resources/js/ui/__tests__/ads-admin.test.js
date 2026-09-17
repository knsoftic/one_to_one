// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import axios from '../../bootstrap';
import { toast } from '../../lib/toast';
import { initAdminAds } from '../admin-ads';
import { initAdsSettings } from '../ads-settings';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), patch: vi.fn(async () => ({ data: {} })) } }));
vi.mock('../../lib/toast', () => ({ toast: vi.fn() }));

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('Admin ads editor preview', () => {
    it('mirrors the fields into the sponsored card and counts countries', () => {
        document.body.innerHTML = `
            <form data-ad-editor>
                <input data-ad-field="title" value="">
                <textarea data-ad-field="body"></textarea>
                <input data-ad-field="cta" value="">
                <input data-ad-field="sponsor" value="">
                <div class="admin-ad-countries"><summary> All countries </summary>
                    <input type="checkbox" name="countries[]" value="PK"><input type="checkbox" name="countries[]" value="AE"></div>
                <div data-ad-preview>
                    <span data-ad-preview-tag>Sponsored</span>
                    <span data-ad-preview-title>x</span>
                    <span data-ad-preview-body></span>
                    <span data-ad-preview-cta>y</span>
                </div>
            </form>`;
        initAdminAds(document.querySelector('[data-ad-editor]'));

        const title = document.querySelector('[data-ad-field="title"]');
        title.value = 'Half price';
        title.dispatchEvent(new Event('input'));
        expect(document.querySelector('[data-ad-preview-title]').textContent).toBe('Half price');

        const sponsor = document.querySelector('[data-ad-field="sponsor"]');
        sponsor.value = 'ACME';
        sponsor.dispatchEvent(new Event('input'));
        expect(document.querySelector('[data-ad-preview-tag]').textContent).toBe('Sponsored · ACME');

        const pk = document.querySelector('input[value="PK"]');
        pk.checked = true;
        pk.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.querySelector('.admin-ad-countries summary').textContent).toContain('1 selected');
    });
});

describe('Settings → Privacy → Ads', () => {
    const render = (on = false) => {
        document.body.innerHTML = `
            <div data-ads-settings data-route-consent="/ads/consent" data-route-profile="/ads/profile">
                <input type="checkbox" data-ads-personalised ${on ? 'checked' : ''}>
                <div data-ads-details ${on ? '' : 'hidden'}>
                    <input type="checkbox" data-ads-location>
                    <select data-ads-gender><option value="">-</option><option value="female">F</option></select>
                    <input type="number" data-ads-birth-year>
                    <dl data-ads-data></dl>
                </div>
            </div>`;
        return document.querySelector('[data-ads-settings]');
    };

    it('turns personalised ads on and reveals the options', async () => {
        axios.post.mockResolvedValue({ data: { data: { Country: 'PK' } } });
        initAdsSettings(render(false));

        const toggle = document.querySelector('[data-ads-personalised]');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();

        expect(axios.post).toHaveBeenCalledWith('/ads/consent', expect.objectContaining({ personalised: true }));
        expect(document.querySelector('[data-ads-details]').hidden).toBe(false);
        expect(document.querySelector('[data-ads-data]').textContent).toContain('PK');
    });

    it('saves the optional gender and reverts the toggle on failure', async () => {
        axios.patch.mockResolvedValue({ data: { data: { Gender: 'female' } } });
        initAdsSettings(render(true));
        const gender = document.querySelector('[data-ads-gender]');
        gender.value = 'female';
        gender.dispatchEvent(new Event('change'));
        await Promise.resolve();
        expect(axios.patch).toHaveBeenCalledWith('/ads/profile', { gender: 'female' });

        axios.post.mockRejectedValueOnce(new Error('nope'));
        const toggle = document.querySelector('[data-ads-personalised]');
        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();
        expect(toggle.checked).toBe(true); // reverted
        expect(toast).toHaveBeenCalled();
    });
});
