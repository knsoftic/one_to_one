// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

import { initResendCountdown, initWebOtp } from '../otp';

function page(wait) {
    document.body.innerHTML = `
        <form data-otp-form><input data-otp-input name="code"><button type="submit">Log in</button></form>
        <form data-otp-resend data-wait="${wait}">
            <button type="submit" data-otp-resend-button>Didn't get it? <span data-otp-resend-label></span></button>
        </form>`;
    return {
        form: document.querySelector('[data-otp-form]'),
        input: document.querySelector('[data-otp-input]'),
        resend: document.querySelector('[data-otp-resend]'),
        button: document.querySelector('[data-otp-resend-button]'),
        label: document.querySelector('[data-otp-resend-label]'),
    };
}

afterEach(() => {
    vi.useRealTimers();
    delete window.OTPCredential;
    document.body.innerHTML = '';
});

describe('SMS code page (A1)', () => {
    it('counts down to "Send again"', () => {
        vi.useFakeTimers();
        const { resend, button, label } = page(3);

        const countdown = initResendCountdown(resend);
        expect(button.disabled).toBe(true);
        expect(label.textContent).toBe('Send again in 3s');

        vi.advanceTimersByTime(2000);
        expect(label.textContent).toBe('Send again in 1s');
        vi.advanceTimersByTime(1000);
        expect(button.disabled).toBe(false);
        expect(label.textContent).toBe('Send again');
        expect(countdown.left()).toBe(0);
    });

    it('can send again straight away when there is no wait', () => {
        const { resend, button, label } = page(0);
        initResendCountdown(resend);
        expect(button.disabled).toBe(false);
        expect(label.textContent).toBe('Send again');
        expect(initResendCountdown(null)).toBeNull();
    });

    it('fills in the code from the SMS where the phone offers it', async () => {
        const { form, input } = page(0);
        window.OTPCredential = function OTPCredential() {};
        const get = vi.fn().mockResolvedValue({ code: '482913' });
        Object.defineProperty(navigator, 'credentials', { value: { get }, configurable: true });
        const submitted = vi.fn();
        form.requestSubmit = submitted;

        initWebOtp(form);
        await Promise.resolve();
        await Promise.resolve();

        expect(get).toHaveBeenCalledWith(expect.objectContaining({ otp: { transport: ['sms'] } }));
        expect(input.value).toBe('482913');
        expect(submitted).toHaveBeenCalled();
    });

    it('does nothing on browsers without WebOTP', () => {
        const { form } = page(0);
        expect(initWebOtp(form)).toBeNull();
    });
});
