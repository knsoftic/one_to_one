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

describe('Settings > Privacy > Ads', () => {
    const render = (allowed = false) => {
        document.body.innerHTML = `
            <div data-ads-settings data-route-profile="/ads/profile">
                <input type="checkbox" data-ads-location ${allowed ? 'checked' : ''}>
                <dl data-ads-data></dl>
            </div>`;
        return document.querySelector('[data-ads-settings]');
    };

    it('turning the area on asks the device, then saves the rounded position', async () => {
        vi.spyOn(navigator, 'geolocation', 'get').mockReturnValue({ getCurrentPosition: (ok) => ok({ coords: { latitude: 24.86, longitude: 67.01 } }) });
        axios.patch.mockResolvedValue({ data: { data: { Country: 'PK', 'Approximate location': '24.86,67.01' } } });
        initAdsSettings(render(false));

        const toggle = document.querySelector('[data-ads-location]');
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(axios.patch).toHaveBeenCalledWith('/ads/profile', { location_allowed: true, lat: 24.86, lng: 67.01 });
        expect(document.querySelector('[data-ads-data]').textContent).toContain('24.86,67.01');
    });

    it('turning it off saves that, and a refused device puts the switch back', async () => {
        axios.patch.mockResolvedValue({ data: { data: {} } });
        initAdsSettings(render(true));
        const toggle = document.querySelector('[data-ads-location]');

        toggle.checked = false;
        toggle.dispatchEvent(new Event('change'));
        await Promise.resolve();
        expect(axios.patch).toHaveBeenCalledWith('/ads/profile', { location_allowed: false });

        vi.spyOn(navigator, 'geolocation', 'get').mockReturnValue({ getCurrentPosition: (_ok, fail) => fail(new Error('denied')) });
        toggle.checked = true;
        toggle.dispatchEvent(new Event('change'));
        await Promise.resolve();
        await Promise.resolve();
        await Promise.resolve();

        expect(toggle.checked).toBe(false); // put back
        expect(toast).toHaveBeenCalled();
    });
});
