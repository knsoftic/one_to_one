import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { statusTime } from './status';

/**
 * Phase 6 — P9 and P10 in the app: where you're signed in, sign devices out,
 * and link a computer by scanning its QR code (or typing its code).
 */

/** The link token inside a scanned QR code, or null. */
export function tokenFromQr(text) {
    const match = String(text ?? '').match(/\/link-device\/([A-Za-z0-9]{40})(?:[/?#]|$)/);
    return match ? match[1] : null;
}

export function normaliseCode(value) {
    return String(value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');
}

export class LinkedDevices {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        if (!chat.api.has('sessions') || !chat.api.has('linkedApprove')) {
            document.querySelectorAll('[data-action="open-linked-devices"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'open-linked-devices') this.open();
        });

        const pending = chat.config.linkDevice;
        if (pending?.token) {
            history.replaceState(history.state, '', chat.config.routes.chat);
            setTimeout(() => this.confirmLink({ token: pending.token }), 300);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Panel                                                               */
    /* ------------------------------------------------------------------ */

    async open() {
        this.close();
        const overlay = document.createElement('div');
        overlay.className = 'group-info linked-devices';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Linked devices');
        overlay.innerHTML = '<div class="group-info-backdrop" data-linked-close></div><aside class="group-info-panel" data-linked-body></aside>';
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onClick(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal, .qr-scanner')) this.close();
        };
        document.addEventListener('keydown', this.onKey);

        this.sessions = null;
        this.render();
        await this.load();
    }

    close() {
        if (!this.panel) return;
        document.removeEventListener('keydown', this.onKey);
        this.panel.remove();
        this.panel = null;
        this.previousFocus?.focus?.();
    }

    async load() {
        try {
            this.sessions = (await this.chat.api.sessions()).data ?? [];
            this.error = null;
        } catch (error) {
            this.sessions = [];
            this.error = errorMessage(error, "Couldn't load your devices.");
        }
        this.render();
    }

    render() {
        if (!this.panel) return;
        const sessions = this.sessions;
        const others = (sessions ?? []).filter((session) => !session.current);

        this.panel.querySelector('[data-linked-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-linked-close aria-label="Close linked devices">${raw(icon('x'))}</button>
                <span class="group-info-title">Linked devices</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero linked-hero">
                    <span class="linked-hero-icon">${raw(icon('monitor'))}</span>
                    <p class="group-info-description">Use ${this.chat.config.appName ?? 'the app'} on a computer: open the login page there and scan its QR code.</p>
                    <button type="button" class="btn btn-primary" data-link-device>${raw(icon('scan-line'))} Link a device</button>
                </section>
                <section class="group-info-section">
                    <span class="group-info-label">Where you're signed in</span>
                    ${raw(sessions === null
                        ? '<div class="flex justify-center p-4"><span class="spinner"></span></div>'
                        : this.error
                          ? html`<p class="receipt-empty">${this.error}</p>`
                          : sessions.map((session) => html`
                            <div class="linked-row">
                                <span class="session-icon">${raw(icon(session.mobile ? 'smartphone' : 'monitor'))}</span>
                                <span class="linked-row-body">
                                    <span class="linked-row-name">${session.device}${raw(session.current ? ' <span class="badge badge-success">This device</span>' : '')}</span>
                                    <span class="linked-row-meta">${session.ip ?? ''}${session.current ? ' · Active now' : ` · Last active ${statusTime(session.last_active).toLowerCase()}`}</span>
                                </span>
                                ${raw(session.current ? '' : html`<button type="button" class="btn btn-secondary btn-sm" data-session-logout="${session.key}">Log out</button>`)}
                            </div>`).join(''))}
                </section>
                ${raw(others.length ? html`
                    <section class="group-info-section group-info-danger">
                        <button type="button" class="group-info-action is-danger" data-sessions-logout-others>${raw(icon('log-out'))} Log out of all other devices</button>
                    </section>` : '')}
            </div>
        `;
    }

    async onClick(event) {
        const t = event.target;
        if (t.closest('[data-linked-close]')) return this.close();
        if (t.closest('[data-link-device]')) return this.scan();
        const logout = t.closest('[data-session-logout]');
        if (logout) {
            logout.disabled = true;
            try {
                await this.chat.api.logoutSession(logout.dataset.sessionLogout);
                toast.success('That device has been signed out.');
                await this.load();
            } catch (error) {
                logout.disabled = false;
                toast.error(errorMessage(error, "Couldn't sign that device out."));
            }
            return null;
        }
        if (t.closest('[data-sessions-logout-others]')) {
            const choice = await confirmDialog({
                title: 'Log out of all other devices?',
                message: 'Every other browser and phone signed in to your account will be signed out.',
                icon: 'log-out',
                actions: [{ label: 'Log out', value: 'logout', variant: 'danger' }],
            });
            if (choice !== 'logout') return null;
            try {
                const result = await this.chat.api.logoutOtherSessions();
                toast.success(result.message ?? 'Other devices have been signed out.');
                await this.load();
            } catch (error) {
                toast.error(errorMessage(error, "Couldn't sign the other devices out."));
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Scan a QR code (or type the code)                                   */
    /* ------------------------------------------------------------------ */

    async scan() {
        const overlay = document.createElement('div');
        overlay.className = 'qr-scanner';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Scan QR code');
        overlay.innerHTML = html`
            <header class="qr-scanner-bar">
                <button type="button" class="btn-icon" data-scan-close aria-label="Close">${raw(icon('x'))}</button>
                <span class="qr-scanner-title">Scan QR code</span>
            </header>
            <div class="qr-scanner-stage">
                <video playsinline muted data-scan-video></video>
                <span class="qr-scanner-frame" aria-hidden="true"></span>
                <p class="qr-scanner-hint" data-scan-hint>Point your camera at the QR code on the computer's login page.</p>
            </div>
            <form class="qr-scanner-code" data-scan-code>
                <label class="form-label" for="link-code">Or type the code shown under it</label>
                <div class="qr-scanner-code-row">
                    <input id="link-code" class="form-control" name="code" maxlength="9" autocomplete="off" autocapitalize="characters" placeholder="ABCD-EFGH">
                    <button type="submit" class="btn btn-primary">Next</button>
                </div>
            </form>
        `;
        document.body.appendChild(overlay);

        let stream = null;
        let frame = null;
        let busy = false;
        const close = () => {
            cancelAnimationFrame(frame);
            clearTimeout(frame);
            stream?.getTracks().forEach((track) => track.stop());
            overlay.remove();
        };
        const found = async (target) => {
            if (busy) return;
            busy = true;
            close();
            await this.confirmLink(target);
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-scan-close]')) close();
        });
        overlay.querySelector('[data-scan-code]').addEventListener('submit', (event) => {
            event.preventDefault();
            const code = normaliseCode(event.target.elements.namedItem('code').value);
            if (code.length !== 8) {
                toast.info('The code has 8 letters and numbers.');
                return;
            }
            found({ code });
        });

        const hint = overlay.querySelector('[data-scan-hint]');
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            hint.textContent = "This device can't scan QR codes here. Type the code shown on the computer instead.";
            overlay.classList.add('is-code-only');
            overlay.querySelector('#link-code').focus();
            return;
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            if (!overlay.isConnected) {
                stream.getTracks().forEach((track) => track.stop());
                return;
            }
            const video = overlay.querySelector('[data-scan-video]');
            video.srcObject = stream;
            await video.play();
            const detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            const tick = async () => {
                if (!overlay.isConnected) return;
                try {
                    const codes = await detector.detect(video);
                    const token = codes.map((c) => tokenFromQr(c.rawValue)).find(Boolean);
                    if (token) {
                        found({ token });
                        return;
                    }
                    if (codes.length) hint.textContent = "That QR code isn't a login code. Scan the one on the login page.";
                } catch {
                    /* keep scanning */
                }
                frame = setTimeout(tick, 250);
            };
            tick();
        } catch {
            hint.textContent = 'Allow the camera to scan, or type the code shown on the computer.';
            overlay.classList.add('is-code-only');
        }
    }

    /** Show which device is asking, then approve it. */
    async confirmLink(target) {
        let info;
        try {
            info = await this.chat.api.lookupLink(target);
        } catch (error) {
            toast.error(errorMessage(error, 'This code has expired. Refresh the login page and try again.'));
            return false;
        }

        const choice = await confirmDialog({
            title: `Link ${info.device}?`,
            message: `${info.ip ? `From ${info.ip}. ` : ''}That device will be signed in to your account and can read and send your messages. Only link a computer you are using right now.`,
            icon: 'monitor',
            tone: 'primary',
            actions: [{ label: 'Link device', value: 'link', variant: 'primary' }],
        });
        if (choice !== 'link') return false;

        try {
            await this.chat.api.approveLink(target);
            toast.success(`${info.device} is now linked.`);
            if (this.panel) await this.load();
            return true;
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't link the device."));
            return false;
        }
    }
}
