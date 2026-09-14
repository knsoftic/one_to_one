/**
 * Phase 7 — the SMS code pages: count down to "Send again", and let phones fill in
 * the code from the SMS by themselves (WebOTP) where the browser supports it.
 */
export function initResendCountdown(form = document.querySelector('[data-otp-resend]'), { tick = 1000 } = {}) {
    if (!form) return null;
    const button = form.querySelector('[data-otp-resend-button]');
    const label = form.querySelector('[data-otp-resend-label]');
    let left = Number.parseInt(form.dataset.wait ?? '0', 10) || 0;
    let timer = null;

    const render = () => {
        button.disabled = left > 0;
        label.textContent = left > 0 ? `Send again in ${left}s` : 'Send again';
    };
    const step = () => {
        left = Math.max(0, left - 1);
        render();
        if (left > 0) timer = setTimeout(step, tick);
    };

    render();
    if (left > 0) timer = setTimeout(step, tick);
    return { stop: () => clearTimeout(timer), left: () => left };
}

export function initWebOtp(form = document.querySelector('[data-otp-form]')) {
    const input = form?.querySelector('[data-otp-input]');
    if (!input || !('OTPCredential' in window) || !navigator.credentials?.get) return null;

    const controller = new AbortController();
    form.addEventListener('submit', () => controller.abort(), { once: true });
    navigator.credentials
        .get({ otp: { transport: ['sms'] }, signal: controller.signal })
        .then((otp) => {
            if (!otp?.code || input.value) return;
            input.value = otp.code;
            form.requestSubmit();
        })
        .catch(() => {
            /* typed by hand */
        });
    return controller;
}

if (typeof window !== 'undefined') {
    initResendCountdown();
    initWebOtp();
}
