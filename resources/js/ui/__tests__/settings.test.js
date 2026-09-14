// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { patch: vi.fn(), post: vi.fn(), get: vi.fn() } }));
vi.mock('../../lib/theme', () => ({ setTheme: vi.fn() }));

import axios from '../../bootstrap';
import { setTheme } from '../../lib/theme';
import { initProfileForm, initSettings, initSettingsNav } from '../settings';

function page({ view = 'list', active = 'profile' } = {}) {
    document.body.innerHTML = `
        <div data-settings data-settings-tabs data-view="${view}" data-active="${active}">
            <aside class="wa-settings-list">
                <button data-settings-open="profile" aria-current="false">Me</button>
                <button data-settings-open="privacy" aria-current="false">Privacy</button>
                <button data-settings-open="chats" aria-current="false">Chats</button>
            </aside>
            <main>
                <section class="wa-section ${active === 'profile' ? 'is-active' : ''}" data-settings-section="profile">
                    <header><button data-settings-back>Back</button><h2 class="wa-appbar-title">Profile</h2></header>
                    <div class="wa-scroll">
                        <form data-profile-form>
                            <input name="name" value="Ayesha">
                            <div data-profile-save><button type="submit">Save changes</button></div>
                        </form>
                        <button data-settings-open="account">Phone</button>
                    </div>
                </section>
                <section class="wa-section" data-settings-section="account"><header><button data-settings-back>Back</button><h2 class="wa-appbar-title">Account</h2></header></section>
                <section class="wa-section ${active === 'privacy' ? 'is-active' : ''}" data-settings-section="privacy">
                    <header><button data-settings-back>Back</button><h2 class="wa-appbar-title">Privacy</h2></header>
                    <label class="wa-row wa-choice">
                        <span data-choice-label>Everyone</span>
                        <select data-preference="last_seen_privacy">
                            <option value="everyone" selected>Everyone</option>
                            <option value="contacts">My contacts</option>
                            <option value="nobody">Nobody</option>
                        </select>
                    </label>
                    <button data-settings-open="blocked">Blocked contacts</button>
                </section>
                <section class="wa-section ${active === 'blocked' ? 'is-active' : ''}" data-settings-section="blocked" data-parent="privacy">
                    <header><button data-settings-back>Back</button><h2 class="wa-appbar-title">Blocked contacts</h2></header>
                </section>
                <section class="wa-section" data-settings-section="chats">
                    <label class="wa-row wa-choice">
                        <span data-choice-label>System default</span>
                        <select data-theme-select><option value="system" selected>System default</option><option value="light">Light</option><option value="dark">Dark</option></select>
                    </label>
                </section>
            </main>
        </div>`;
    return document.querySelector('[data-settings]');
}

const phone = (isPhone = true) => {
    window.matchMedia = vi.fn(() => ({ matches: !isPhone, addEventListener: () => {} }));
};
const click = (selector) => document.querySelector(selector).click();
const active = () => document.querySelector('.wa-section.is-active')?.dataset.settingsSection;

beforeEach(() => {
    history.replaceState(null, '', '/settings');
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
});

describe('WhatsApp-style settings screens', () => {
    it('opens a section from the list on a phone and goes back to the list', () => {
        phone();
        const root = page();
        initSettingsNav(root);
        const push = vi.spyOn(history, 'pushState');
        const back = vi.spyOn(history, 'back').mockImplementation(() => {});

        click('.wa-settings-list [data-settings-open="privacy"]');
        expect(root.dataset.view).toBe('section');
        expect(active()).toBe('privacy');
        expect(location.search).toBe('?tab=privacy');
        expect(push).toHaveBeenCalledTimes(1);
        expect(document.querySelector('.wa-settings-list [data-settings-open="privacy"]').getAttribute('aria-current')).toBe('page');

        // Blocked contacts is inside Privacy: back returns there, not to the list.
        click('[data-settings-section="privacy"] [data-settings-open="blocked"]');
        expect(active()).toBe('blocked');
        expect(push).toHaveBeenCalledTimes(1);
        expect(document.querySelector('.wa-settings-list [data-settings-open="privacy"]').getAttribute('aria-current')).toBe('page');
        click('[data-settings-section="blocked"] [data-settings-back]');
        expect(active()).toBe('privacy');

        // Back from a section opened from the list uses the browser history…
        click('[data-settings-section="privacy"] [data-settings-back]');
        expect(back).toHaveBeenCalledTimes(1);
        history.replaceState(null, '', '/settings');
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(root.dataset.view).toBe('list');
    });

    it('goes to the list when a section was opened directly, and handles the Android back button', () => {
        phone();
        history.replaceState(null, '', '/settings?tab=profile');
        const root = page({ view: 'section', active: 'profile' });
        initSettingsNav(root);

        // Phone row in the profile opens Account without adding a history step.
        const push = vi.spyOn(history, 'pushState');
        click('[data-settings-section="profile"] [data-settings-open="account"]');
        expect(active()).toBe('account');
        expect(push).not.toHaveBeenCalled();

        const event = new CustomEvent('app:back', { cancelable: true });
        document.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(root.dataset.view).toBe('list');
        expect(location.search).toBe('');

        // On the list the app handles "back" itself (leaves the settings page).
        const again = new CustomEvent('app:back', { cancelable: true });
        document.dispatchEvent(again);
        expect(again.defaultPrevented).toBe(false);

        // Forward / back in the browser to a section.
        history.replaceState(null, '', '/settings?tab=chats');
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(active()).toBe('chats');
        expect(root.dataset.view).toBe('section');
    });

    it('keeps the list beside the section on wide screens', () => {
        phone(false);
        const root = page({ view: 'section', active: 'profile' });
        initSettingsNav(root);
        const push = vi.spyOn(history, 'pushState');

        click('.wa-settings-list [data-settings-open="chats"]');
        expect(active()).toBe('chats');
        expect(push).not.toHaveBeenCalled();
        const event = new CustomEvent('app:back', { cancelable: true });
        document.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it('shows the chosen privacy option and puts it back when saving fails', async () => {
        phone();
        page({ view: 'section', active: 'privacy' });
        initSettings({ routes: { preferences: '/settings/preferences' }, user: {} });
        const select = document.querySelector('[data-preference="last_seen_privacy"]');
        const label = select.closest('.wa-choice').querySelector('[data-choice-label]');

        axios.patch.mockResolvedValueOnce({ data: {} });
        select.value = 'nobody';
        select.dispatchEvent(new Event('change'));
        expect(label.textContent).toBe('Nobody');
        await vi.waitFor(() => expect(axios.patch).toHaveBeenCalledWith('/settings/preferences', { last_seen_privacy: 'nobody' }));

        axios.patch.mockRejectedValueOnce(new Error('offline'));
        select.value = 'contacts';
        select.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(label.textContent).toBe('Nobody'));
        expect(select.value).toBe('nobody');
    });

    it('changes the theme from Chats and follows the theme button', () => {
        phone();
        page();
        initSettings({ routes: { preferences: '/settings/preferences' }, user: {} });
        const select = document.querySelector('[data-theme-select]');

        select.value = 'dark';
        select.dispatchEvent(new Event('change'));
        expect(setTheme).toHaveBeenCalledWith('dark');
        expect(select.closest('.wa-choice').querySelector('[data-choice-label]').textContent).toBe('Dark');

        document.dispatchEvent(new CustomEvent('theme:change', { detail: { preference: 'light', dark: false } }));
        expect(select.value).toBe('light');
        expect(select.closest('.wa-choice').querySelector('[data-choice-label]').textContent).toBe('Light');
    });

    it('shows "Save changes" on the profile only after an edit', () => {
        phone();
        page({ view: 'section', active: 'profile' });
        const form = document.querySelector('[data-profile-form]');
        initProfileForm(form);
        const bar = form.querySelector('[data-profile-save]');

        expect(bar.hidden).toBe(true);
        form.querySelector('input').dispatchEvent(new Event('input', { bubbles: true }));
        expect(bar.hidden).toBe(false);

        // With errors it stays visible.
        form.insertAdjacentHTML('afterbegin', '<span class="form-error">Too short</span>');
        bar.hidden = false;
        initProfileForm(form);
        expect(bar.hidden).toBe(false);
    });
});
