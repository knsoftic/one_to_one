import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * Phase 6 — P8: app lock in the Android app. The phone's fingerprint, face or
 * screen lock (PIN / pattern) unlocks the app when it opens or comes back after
 * the chosen time. Settings stay on this phone.
 */
export const SETTINGS_KEY = 'native:app-lock';
export const HIDDEN_AT_KEY = 'native:app-lock-hidden-at';

/** Seconds away before the app locks again. */
export const TIMEOUTS = [
    [0, 'Immediately'],
    [60, 'After 1 minute'],
    [1800, 'After 30 minutes'],
];

export function readSettings(storage) {
    try {
        const value = JSON.parse(storage.getItem(SETTINGS_KEY) ?? '{}');
        return { enabled: Boolean(value.enabled), timeout: TIMEOUTS.some(([s]) => s === value.timeout) ? value.timeout : 0 };
    } catch {
        return { enabled: false, timeout: 0 };
    }
}

/** Lock when turned on and the app was away long enough (or the app just started). */
export function shouldLock(settings, hiddenAt, now = Date.now()) {
    if (!settings.enabled) return false;
    if (!hiddenAt) return true;
    return now - hiddenAt >= settings.timeout * 1000;
}

export class AppLock {
    constructor({ NativeApp, App, storage = window.localStorage, appName = 'the app' }) {
        this.native = NativeApp;
        this.app = App;
        this.storage = storage;
        this.appName = appName;
        this.overlay = null;
        this.supported = false;
    }

    get settings() {
        return readSettings(this.storage);
    }

    save(settings) {
        try {
            this.storage.setItem(SETTINGS_KEY, JSON.stringify(settings));
        } catch {
            /* storage unavailable */
        }
    }

    /** Check support, lock on start, and lock again when the app comes back. */
    async init({ signedIn = true } = {}) {
        const status = await this.native.getAppLockStatus?.().catch(() => null);
        this.status = status;
        this.supported = Boolean(status?.available);
        this.bindSettings();

        if (!this.supported || !signedIn) return;

        if (shouldLock(this.settings, Number(this.storage.getItem(HIDDEN_AT_KEY)) || 0)) this.lock();

        this.app.addListener?.('appStateChange', ({ isActive }) => {
            if (!isActive) {
                try {
                    this.storage.setItem(HIDDEN_AT_KEY, String(Date.now()));
                } catch {
                    /* storage unavailable */
                }
            } else if (shouldLock(this.settings, Number(this.storage.getItem(HIDDEN_AT_KEY)) || 0)) {
                this.lock();
            }
        });
    }

    lock() {
        if (this.overlay) return;
        const overlay = document.createElement('div');
        overlay.className = 'app-lock';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', `${this.appName} is locked`);
        overlay.innerHTML = html`
            <div class="app-lock-body">
                <span class="app-lock-icon">${raw(icon('lock'))}</span>
                <h2 class="app-lock-title">${this.appName} is locked</h2>
                <p class="app-lock-text">Use your fingerprint, face or phone screen lock to open it.</p>
                <button type="button" class="btn btn-primary btn-lg" data-app-unlock>${raw(icon('fingerprint-pattern'))} Unlock</button>
            </div>
        `;
        overlay.querySelector('[data-app-unlock]').addEventListener('click', () => this.unlock());
        document.body.appendChild(overlay);
        document.documentElement.classList.add('is-app-locked');
        this.overlay = overlay;
        this.unlock();
    }

    async unlock() {
        if (this.unlocking) return false;
        this.unlocking = true;
        try {
            const result = await this.native.unlockApp({ title: `Unlock ${this.appName}`, subtitle: 'Fingerprint, face or phone screen lock' });
            if (result?.unlocked) {
                this.overlay?.remove();
                this.overlay = null;
                document.documentElement.classList.remove('is-app-locked');
                try {
                    this.storage.removeItem(HIDDEN_AT_KEY);
                } catch {
                    /* storage unavailable */
                }
                return true;
            }
            return false;
        } catch {
            return false;
        } finally {
            this.unlocking = false;
        }
    }

    /** Turning it on asks for the fingerprint / screen lock first. */
    async setEnabled(enabled) {
        if (enabled) {
            const result = await this.native.unlockApp({ title: 'Turn on app lock', subtitle: 'Confirm it\'s you' }).catch(() => null);
            if (!result?.unlocked) return false;
        }
        this.save({ ...this.settings, enabled });
        return true;
    }

    setTimeoutSeconds(seconds) {
        this.save({ ...this.settings, timeout: Number(seconds) });
    }

    /** The App lock row on the settings page (only shown inside the app). */
    bindSettings(root = document) {
        const row = root.querySelector('[data-app-lock-row]');
        if (!row) return;

        const toggle = row.querySelector('[data-app-lock-toggle]');
        const timeout = row.querySelector('[data-app-lock-timeout]');
        const text = row.querySelector('[data-app-lock-text]');
        row.hidden = false;

        if (!this.supported) {
            toggle.disabled = true;
            timeout.disabled = true;
            text.textContent = this.status?.reason === 'no_screen_lock'
                ? 'Set a screen lock (PIN, pattern or fingerprint) on your phone to use app lock.'
                : 'This phone or app version can\'t lock the app. Update the app to use it.';
            return;
        }

        toggle.checked = this.settings.enabled;
        timeout.value = String(this.settings.timeout);
        timeout.disabled = !toggle.checked;

        toggle.addEventListener('change', async () => {
            const wanted = toggle.checked;
            const ok = await this.setEnabled(wanted);
            if (!ok) {
                toggle.checked = !wanted;
                return;
            }
            timeout.disabled = !wanted;
            toast.success(wanted ? 'App lock is on.' : 'App lock is off.', { timeout: 2000 });
        });
        timeout.addEventListener('change', () => {
            this.setTimeoutSeconds(timeout.value);
            toast.success('Saved.', { timeout: 1500 });
        });
    }
}
