// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../lib/toast', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

import { initForms } from '../forms';

initForms();

afterEach(() => {
    document.body.innerHTML = '';
});

const submit = (form) => {
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);
    return event;
};

describe('Loading buttons on forms', () => {
    it('shows a spinner and stops double submits', () => {
        document.body.innerHTML = '<form data-loading-form><button type="submit">Save</button></form>';
        const form = document.querySelector('form');
        const button = form.querySelector('button');

        expect(submit(form).defaultPrevented).toBe(false);
        expect(button.classList.contains('is-loading')).toBe(true);
        expect(submit(form).defaultPrevented).toBe(true);
    });

    it('waits for the confirmation on forms that ask first (delete my account)', () => {
        document.body.innerHTML = '<form data-loading-form data-confirm="Sure?"><button type="submit">Delete my account</button></form>';
        const form = document.querySelector('form');
        const button = form.querySelector('button');

        // The confirm dialog opens first: no spinner, nothing blocked.
        expect(submit(form).defaultPrevented).toBe(false);
        expect(button.classList.contains('is-loading')).toBe(false);

        // Confirmed: the real submit goes through with the spinner.
        form.dataset.confirmed = '1';
        expect(submit(form).defaultPrevented).toBe(false);
        expect(button.classList.contains('is-loading')).toBe(true);
    });
});
