import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { qrSvg } from '../lib/qr';
import { canScanQr, startQrCamera } from '../lib/qr-scanner';
import { toast } from '../lib/toast';
import * as T from './templates';

/**
 * Phase 7 — A4 Profile QR code: show my code, share or reset it, and scan someone
 * else's code to start a chat with them.
 */

/** The profile token inside a scanned QR code or a pasted link, or null. */
export function profileTokenFromQr(text) {
    const match = String(text ?? '').trim().match(/\/u\/([A-Za-z0-9]{32})(?:[/?#]|$)/);
    return match ? match[1] : null;
}

export class ProfileQr {
    constructor(chat) {
        this.chat = chat;
        this.panel = null;
        this.code = null;
        if (!chat.api.has('profileQr') || !chat.api.has('profileQrLookup')) {
            document.querySelectorAll('[data-action="open-qr-code"]').forEach((el) => el.remove());
            return;
        }

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'open-qr-code') this.open();
        });

        // Opened by scanning a code with the phone's camera.
        const pending = chat.config.profileQr;
        if (pending?.token) {
            history.replaceState(history.state, '', chat.config.routes.chat);
            setTimeout(() => this.openChat(pending.token), 300);
        }
    }

    get appName() {
        return this.chat.config.appName ?? 'the app';
    }

    /* ------------------------------------------------------------------ */
    /* My code                                                             */
    /* ------------------------------------------------------------------ */

    async open() {
        this.close();
        const overlay = document.createElement('div');
        overlay.className = 'group-info profile-qr-panel';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'QR code');
        overlay.innerHTML = '<div class="group-info-backdrop" data-qr-close></div><aside class="group-info-panel" data-qr-body></aside>';
        document.body.appendChild(overlay);
        this.panel = overlay;
        this.previousFocus = document.activeElement;

        overlay.addEventListener('click', (event) => this.onClick(event));
        this.onKey = (event) => {
            if (event.key === 'Escape' && !document.querySelector('.modal, .qr-scanner')) this.close();
        };
        document.addEventListener('keydown', this.onKey);

        this.error = null;
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
            this.code = await this.chat.api.profileQr();
            this.error = null;
        } catch (error) {
            this.error = errorMessage(error, "Couldn't load your QR code.");
        }
        this.render();
    }

    render() {
        if (!this.panel) return;
        const me = this.chat.me;
        const ready = Boolean(this.code?.url);
        const box = ready
            ? qrSvg(this.code.url)
            : this.error
              ? html`<p class="receipt-empty">${this.error}</p>`
              : '<span class="spinner"></span>';

        this.panel.querySelector('[data-qr-body]').innerHTML = html`
            <header class="group-info-header">
                <button type="button" class="btn-icon" data-qr-close aria-label="Close QR code">${raw(icon('x'))}</button>
                <span class="group-info-title">QR code</span>
            </header>
            <div class="group-info-scroll">
                <section class="group-info-hero profile-qr-hero">
                    ${raw(T.avatar(me, 'xl'))}
                    <span class="group-info-name">${me.name}</span>
                    <span class="group-info-count">${this.appName} contact</span>
                    <div class="group-invite-qr profile-qr-code" role="img" aria-label="Your QR code">${raw(box)}</div>
                    <p class="group-info-description">Your QR code is private. If you share it with someone, they can scan it with ${this.appName} to start a chat with you.</p>
                </section>
                <section class="group-info-section">
                    <button type="button" class="group-info-action" data-qr-scan>${raw(icon('scan-qr-code'))} Scan code</button>
                    <button type="button" class="group-info-action" data-qr-share ${ready ? '' : 'disabled'}>${raw(icon('share-2'))} Share link</button>
                </section>
                <section class="group-info-section group-info-danger">
                    <button type="button" class="group-info-action is-danger" data-qr-reset ${ready ? '' : 'disabled'}>${raw(icon('refresh-cw'))} Reset QR code</button>
                </section>
            </div>
        `;
    }

    async onClick(event) {
        const t = event.target;
        if (t.closest('[data-qr-close]')) return this.close();
        if (t.closest('[data-qr-scan]')) return this.scan();
        if (t.closest('[data-qr-share]')) return this.share();
        if (t.closest('[data-qr-reset]')) return this.reset();
        return null;
    }

    async share() {
        const url = this.code?.url;
        if (!url) return;
        if (navigator.share) {
            try {
                await navigator.share({ title: this.chat.me.name, text: `Chat with me on ${this.appName}`, url });
            } catch {
                /* closed the share sheet */
            }
            return;
        }
        try {
            await navigator.clipboard.writeText(url);
            toast.success('Link copied.', { timeout: 2000 });
        } catch {
            toast.error("Couldn't copy the link.");
        }
    }

    async reset() {
        const choice = await confirmDialog({
            title: 'Reset your QR code?',
            message: "Your current QR code and link will stop working. People who already chat with you aren't affected.",
            icon: 'refresh-cw',
            tone: 'primary',
            actions: [{ label: 'Reset', value: 'reset', variant: 'primary' }],
        });
        if (choice !== 'reset') return;
        try {
            const result = await this.chat.api.resetProfileQr();
            this.code = result;
            toast.success(result.message ?? 'Your QR code has been reset.');
            this.render();
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't reset your QR code."));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Scan someone's code                                                 */
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
                <span class="qr-scanner-title">Scan code</span>
            </header>
            <div class="qr-scanner-stage">
                <video playsinline muted data-scan-video></video>
                <span class="qr-scanner-frame" aria-hidden="true"></span>
                <p class="qr-scanner-hint" data-scan-hint>Point your camera at someone's ${this.appName} QR code.</p>
            </div>
            <form class="qr-scanner-code" data-scan-link>
                <label class="form-label" for="profile-qr-link">Or paste the link to their QR code</label>
                <div class="qr-scanner-code-row">
                    <input id="profile-qr-link" class="form-control profile-qr-input" name="link" type="url" autocomplete="off" placeholder="https://…/u/…">
                    <button type="submit" class="btn btn-primary">Open</button>
                </div>
            </form>
        `;
        document.body.appendChild(overlay);

        let stopCamera = null;
        let busy = false;
        const close = () => {
            stopCamera?.();
            overlay.remove();
        };
        const found = async (token) => {
            if (busy) return;
            busy = true;
            close();
            await this.openChat(token);
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-scan-close]')) close();
        });
        overlay.querySelector('[data-scan-link]').addEventListener('submit', (event) => {
            event.preventDefault();
            const token = profileTokenFromQr(event.target.elements.namedItem('link').value);
            if (!token) {
                toast.info(`That isn't a link to a ${this.appName} QR code.`);
                return;
            }
            found(token);
        });

        const hint = overlay.querySelector('[data-scan-hint]');
        if (!canScanQr()) {
            hint.textContent = "This device can't scan QR codes here. Paste the link to their QR code instead.";
            overlay.classList.add('is-code-only');
            overlay.querySelector('#profile-qr-link').focus();
            return;
        }

        try {
            const stop = await startQrCamera(overlay.querySelector('[data-scan-video]'), (values) => {
                const token = values.map(profileTokenFromQr).find(Boolean);
                if (token) {
                    found(token);
                    return true;
                }
                hint.textContent = `That isn't a ${this.appName} QR code.`;
                return false;
            });
            if (overlay.isConnected) stopCamera = stop;
            else stop();
        } catch {
            hint.textContent = 'Allow the camera to scan, or paste the link to their QR code.';
            overlay.classList.add('is-code-only');
        }
    }

    /** Whose code is it? Then offer to start the chat. */
    async openChat(token) {
        let info;
        try {
            info = await this.chat.api.lookupProfileQr(token);
        } catch (error) {
            toast.error(errorMessage(error, "This QR code isn't valid anymore. Ask for a new one."));
            return false;
        }

        if (info.self) {
            toast.info("That's your own QR code.");
            return false;
        }

        const user = info.user;
        const choice = await confirmDialog({
            title: `Chat with ${info.saved_name ?? user.name}?`,
            message: `@${user.username}${user.about ? ` · ${user.about}` : ''}`,
            icon: 'message-circle',
            tone: 'primary',
            actions: [{ label: 'Message', value: 'chat', variant: 'primary' }],
        });
        if (choice !== 'chat') return false;

        this.close();
        this.chat.rememberUser?.(user);
        await this.chat.startConversationWith(user.id);
        return true;
    }
}
