// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindSearchShortcut, initAdminUi, initSettingsPage, updateTabEdges } from '../admin-ui';

const key = (target, init) => target.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, cancelable: true, ...init }));

describe('Admin panel UI', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('only runs on admin pages', () => {
        document.body.innerHTML = '<div class="chat-app"></div>';
        expect(initAdminUi()).toBeNull();
    });

    it('"/" jumps to the people search, but not while typing', () => {
        document.body.innerHTML = '<textarea></textarea><form><input data-admin-search value="x"></form>';
        const input = document.querySelector('[data-admin-search]');
        Object.defineProperty(input, 'offsetParent', { get: () => document.body });
        const stop = bindSearchShortcut();

        const textarea = document.querySelector('textarea');
        textarea.focus();
        key(textarea, { key: '/' });
        expect(document.activeElement).toBe(textarea);

        textarea.blur();
        key(document.body, { key: 'k', ctrlKey: true });
        expect(document.activeElement).not.toBe(input);
        key(document.body, { key: '/' });
        expect(document.activeElement).toBe(input);

        stop();
    });

    it('fades the side of a tab row where tabs are hidden', () => {
        const row = document.createElement('nav');
        document.body.append(row);
        Object.defineProperties(row, { scrollWidth: { value: 600 }, clientWidth: { value: 300 } });

        row.scrollLeft = 0;
        updateTabEdges(row);
        expect([...row.classList]).toEqual(['has-more-end']);

        row.scrollLeft = 150;
        updateTabEdges(row);
        expect(row.classList.contains('has-more-start')).toBe(true);
        expect(row.classList.contains('has-more-end')).toBe(true);

        row.scrollLeft = 300;
        updateTabEdges(row);
        expect([...row.classList]).toEqual(['has-more-start']);
    });

    it('marks the section on screen and notices unsaved changes', () => {
        const observed = [];
        let callback;
        vi.stubGlobal('IntersectionObserver', class {
            constructor(cb) {
                callback = cb;
            }
            observe(element) {
                observed.push(element.id);
            }
        });
        document.body.innerHTML = `
            <nav data-settings-nav><a href="#brand">Brand</a><a href="#sms">SMS</a><a href="#missing">Gone</a></nav>
            <section id="brand"></section>
            <form data-settings-form><section id="sms"><input name="sms_driver"></section></form>`;

        const { mark } = initSettingsPage(document, window);
        expect(observed).toEqual(['brand', 'sms']);

        callback([
            { target: document.getElementById('brand'), isIntersecting: false, boundingClientRect: { top: -400 } },
            { target: document.getElementById('sms'), isIntersecting: true, boundingClientRect: { top: 120 } },
        ]);
        const sms = document.querySelector('a[href="#sms"]');
        expect(sms.classList.contains('is-active')).toBe(true);
        expect(sms.getAttribute('aria-current')).toBe('true');
        expect(document.querySelector('a[href="#brand"]').classList.contains('is-active')).toBe(false);

        mark('brand');
        expect(sms.hasAttribute('aria-current')).toBe(false);

        const form = document.querySelector('[data-settings-form]');
        form.querySelector('input').dispatchEvent(new Event('input', { bubbles: true }));
        expect(form.classList.contains('is-dirty')).toBe(true);
        form.dispatchEvent(new Event('submit'));
        expect(form.classList.contains('is-dirty')).toBe(false);
    });
});
