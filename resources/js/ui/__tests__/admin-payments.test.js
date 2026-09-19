// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { bindNoteForms, initAdminPayments, runPayCheck } from '../admin-payments';

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('Admin payments UI', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('posts the check URL of the pressed gateway and shows the answer', async () => {
        document.body.innerHTML = `
            <button data-pay-check="stripe" data-url="/admin/settings/pay-check/stripe">Test Stripe</button>
            <button data-pay-check="paypal" data-url="/admin/settings/pay-check/paypal">Test PayPal</button>
            <p data-pay-check-result hidden></p>`;
        const http = {
            post: vi.fn().mockResolvedValue({
                data: { ok: true, message: 'Stripe answers (test mode).' },
            }),
        };
        const { buttons, output } = initAdminPayments(document, { http });

        buttons[0].click();
        expect(buttons[0].disabled).toBe(true);
        expect(output.hidden).toBe(false);
        expect(output.dataset.state).toBe('busy');
        await flush();

        expect(http.post).toHaveBeenCalledWith('/admin/settings/pay-check/stripe');
        expect(output.dataset.state).toBe('ok');
        expect(output.textContent).toContain('Stripe answers (test mode).');
        expect(buttons[0].disabled).toBe(false);

        http.post.mockResolvedValueOnce({
            data: { ok: false, message: 'PayPal refused the credentials: 401' },
        });
        buttons[1].click();
        await flush();
        expect(http.post).toHaveBeenLastCalledWith('/admin/settings/pay-check/paypal');
        expect(output.dataset.state).toBe('bad');
        expect(output.textContent).toContain('PayPal refused');
    });

    it('explains a failed request and escapes the message', async () => {
        document.body.innerHTML = '<button data-pay-check="play" data-url="/x"></button><p data-pay-check-result hidden></p>';
        const button = document.querySelector('button');
        const output = document.querySelector('p');

        await runPayCheck(button, output, {
            http: {
                post: vi.fn().mockRejectedValue({ response: { status: 429 } }),
            },
        });
        expect(output.dataset.state).toBe('bad');
        expect(output.textContent).toContain('Too many checks');

        await runPayCheck(button, output, {
            http: {
                post: vi.fn().mockResolvedValue({
                    data: { ok: false, message: '<b>x</b>' },
                }),
            },
        });
        expect(output.innerHTML).toContain('&lt;b&gt;x&lt;/b&gt;');
        expect(output.querySelector('b')).toBeNull();
    });

    it('copies the neighbouring text on [data-copy]', async () => {
        document.body.innerHTML = '<div><code data-copy-source>TXN-1</code> <button data-copy>Copy</button></div>';
        const writeText = vi.fn().mockResolvedValue();
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText },
            configurable: true,
        });
        initAdminPayments(document, { http: { post: vi.fn() } });

        document.querySelector('[data-copy]').click();
        await flush();
        expect(writeText).toHaveBeenCalledWith('TXN-1');
        expect(document.querySelector('[data-copy]').textContent).toContain('Copied');
    });

    it('requires a note and a confirmation before rejecting or refunding', async () => {
        document.body.innerHTML = `
            <form data-pay-note="reject" action="/reject"><input name="note" value=""><button type="submit">Reject</button></form>
            <form data-pay-note="refund" action="/refund"><input name="note" value="Bank reversed it"><button type="submit">Refund</button></form>`;
        const [reject, refund] = document.querySelectorAll('form');
        const requestSubmit = vi.fn();
        reject.requestSubmit = requestSubmit;
        refund.requestSubmit = requestSubmit;
        const confirm = vi.fn().mockResolvedValue('yes');
        bindNoteForms(document, { confirm });

        reject.dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        expect(confirm).not.toHaveBeenCalled();
        expect(reject.querySelector('[name="note"]').classList.contains('is-invalid')).toBe(true);
        expect(requestSubmit).not.toHaveBeenCalled();

        refund.dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        expect(confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Refund this payment?' }));
        expect(refund.dataset.confirmed).toBe('1');
        expect(requestSubmit).toHaveBeenCalledTimes(1);

        // Cancelling the dialog keeps the form unsent.
        confirm.mockResolvedValueOnce(null);
        reject.querySelector('[name="note"]').value = 'Blurry';
        reject.dispatchEvent(new Event('submit', { cancelable: true }));
        await flush();
        expect(reject.dataset.confirmed).toBeUndefined();
        expect(requestSubmit).toHaveBeenCalledTimes(1);
    });
});
