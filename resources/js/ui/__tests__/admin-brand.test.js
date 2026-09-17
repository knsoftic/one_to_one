// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { initAdminBrand } from '../admin-brand';

const render = ({ remove = true } = {}) => {
    document.body.innerHTML = `
        <form data-brand-form>
            <input data-brand-name value="One2One Chat">
            <input type="file" data-brand-icon>
            <input type="color" data-brand-color value="#4338ca">
            ${remove ? '<input type="checkbox" data-brand-remove>' : ''}
            <div data-brand-preview>
                <span class="admin-brand-tile is-square"><img src="/icons/icon-192.png" data-brand-image></span>
                <span data-brand-label>One2One Chat</span>
                <span class="admin-brand-tile is-circle"><img src="/storage/brand/a/maskable-512.png" data-brand-image data-brand-padded></span>
                <span data-brand-label>One2One Chat</span>
            </div>
        </form>`;
    return document.querySelector('form');
};

const choose = (input, files) => {
    Object.defineProperty(input, 'files', { configurable: true, value: files });
    input.dispatchEvent(new Event('change'));
};

describe('Admin app name & icon preview', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('does nothing on other pages', () => {
        expect(initAdminBrand(null)).toBeNull();
    });

    it('shows the typed name under both icons', () => {
        const form = render();
        initAdminBrand(form);
        const name = form.querySelector('[data-brand-name]');

        name.value = 'Hunario';
        name.dispatchEvent(new Event('input'));
        expect([...form.querySelectorAll('[data-brand-label]')].map((label) => label.textContent)).toEqual(['Hunario', 'Hunario']);

        // An empty name keeps the saved one in the preview.
        name.value = '  ';
        name.dispatchEvent(new Event('input'));
        expect(form.querySelector('[data-brand-label]').textContent).toBe('One2One Chat');
    });

    it('previews a chosen picture, and goes back when it is cleared', () => {
        const form = render();
        initAdminBrand(form, { createUrl: () => 'blob:logo' });
        const input = form.querySelector('[data-brand-icon]');
        const remove = form.querySelector('[data-brand-remove]');
        const [square, circle] = form.querySelectorAll('[data-brand-image]');
        remove.checked = true;

        choose(input, [new File(['x'], 'logo.png', { type: 'image/png' })]);
        expect(square.getAttribute('src')).toBe('blob:logo');
        expect(circle.getAttribute('src')).toBe('blob:logo');
        expect(circle.parentElement.classList.contains('is-padded')).toBe(true);
        expect(square.parentElement.classList.contains('is-padded')).toBe(false);
        expect(remove.checked).toBe(false);

        choose(input, []);
        expect(square.getAttribute('src')).toBe('/icons/icon-192.png');
        expect(circle.getAttribute('src')).toBe('/storage/brand/a/maskable-512.png');
        expect(circle.parentElement.classList.contains('is-padded')).toBe(false);

        // A file that isn't a picture is not previewed.
        choose(input, [new File(['x'], 'notes.txt', { type: 'text/plain' })]);
        expect(square.getAttribute('src')).toBe('/icons/icon-192.png');
    });

    it('colours the preview background', () => {
        const form = render({ remove: false });
        initAdminBrand(form);
        const color = form.querySelector('[data-brand-color]');
        color.value = '#0f766e';
        color.dispatchEvent(new Event('input'));
        expect(form.querySelector('[data-brand-preview]').style.getPropertyValue('--brand-color')).toBe('#0f766e');
    });
});
