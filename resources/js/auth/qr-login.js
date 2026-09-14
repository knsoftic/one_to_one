import axios from '../bootstrap';
import { qrSvg } from '../lib/qr';

/**
 * Phase 6 — P10: the login page shows a QR code; a signed-in phone scans and
 * approves it, and this page signs in. Only this page knows the link's secret.
 */
export const POLL_MS = 2000;

export function initQrLogin(root = document.querySelector('[data-qr-login]'), { poll = POLL_MS } = {}) {
    if (!root) return null;
    const box = root.querySelector('[data-qr-box]');
    const code = root.querySelector('[data-qr-code]');
    let link = null;
    let timer = null;
    let stopped = false;

    const showExpired = () => {
        clearTimeout(timer);
        box.innerHTML = '<button type="button" class="qr-login-refresh" data-qr-refresh>↻ Get a new code</button>';
        box.classList.add('is-expired');
        code.textContent = '—';
    };

    const check = async () => {
        if (stopped || !link) return;
        try {
            const { data } = await axios.post(`${root.dataset.statusUrl.replace('__TOKEN__', link.token)}`, { secret: link.secret });
            if (data.state === 'approved') {
                stopped = true;
                box.classList.add('is-approved');
                window.location.assign(data.redirect);
                return;
            }
            if (data.state === 'expired') {
                showExpired();
                return;
            }
        } catch {
            /* try again on the next tick */
        }
        timer = setTimeout(check, poll);
    };

    const start = async () => {
        clearTimeout(timer);
        box.classList.remove('is-expired');
        box.innerHTML = '<span class="spinner"></span>';
        try {
            const { data } = await axios.post(root.dataset.createUrl);
            link = data;
            box.innerHTML = qrSvg(data.url);
            code.textContent = data.code;
            timer = setTimeout(check, poll);
        } catch {
            box.innerHTML = '<p class="qr-login-error">Couldn\'t load a QR code. Sign in with your password instead.</p>';
        }
    };

    box.addEventListener('click', (event) => {
        if (event.target.closest('[data-qr-refresh]')) start();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && link && !stopped) {
            clearTimeout(timer);
            check();
        }
    });

    start();
    return { start, check, stop: () => { stopped = true; clearTimeout(timer); } };
}

if (typeof window !== 'undefined' && document.querySelector('[data-qr-login]')) {
    // Phones sign in to the app with the password; the QR code is for computers.
    if (window.matchMedia('(min-width: 900px)').matches) initQrLogin();
}
