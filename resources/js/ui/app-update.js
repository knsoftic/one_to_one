import axios from '../bootstrap';
import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

const SKIP_KEY = (code) => `app-update:skipped:${code}`;

function stored(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function store(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* storage unavailable */
    }
}

/* ------------------------------------------------------------------ */
/* Web: a new version was deployed while the tab was open            */
/* ------------------------------------------------------------------ */

/**
 * X4 — every response says which build of the web app is live; when it differs from the
 * build this tab runs, offer a reload (once).
 */
export function initWebUpdateNotice(config, client = axios) {
    const current = config?.version;
    if (!current || current === 'dev') return null;

    let shown = false;
    const id = client.interceptors.response.use((response) => {
        const live = response?.headers?.['x-app-version'];
        if (!shown && live && live !== 'dev' && live !== current) {
            shown = true;
            showReloadNotice(config);
        }
        return response;
    });

    return { stop: () => client.interceptors.response.eject(id) };
}

export function showReloadNotice(config, doc = document) {
    if (doc.querySelector('.app-update-notice')) return null;
    const notice = doc.createElement('div');
    notice.className = 'app-update-notice';
    notice.setAttribute('role', 'status');
    notice.innerHTML = html`
        <span class="app-update-icon">${raw(icon('sparkles'))}</span>
        <span class="app-update-text">A new version of ${config?.name ?? 'the app'} is ready.</span>
        <button type="button" class="btn btn-primary btn-sm" data-app-update-reload>Reload</button>
        <button type="button" class="btn-icon btn-icon-sm" data-app-update-close aria-label="Later">${raw(icon('x'))}</button>
    `;
    notice.addEventListener('click', (event) => {
        if (event.target.closest('[data-app-update-reload]')) window.location.reload();
        if (event.target.closest('[data-app-update-close]')) notice.remove();
    });
    doc.body.appendChild(notice);
    return notice;
}

/* ------------------------------------------------------------------ */
/* Android app: a newer APK was published in the admin panel          */
/* ------------------------------------------------------------------ */

/** 'none' | 'optional' | 'required' */
export function updateKind(update, build) {
    if (!update?.latest_code || !build) return 'none';
    if (build >= update.latest_code) return 'none';
    return update.min_code && build < update.min_code ? 'required' : 'optional';
}

async function openDownload(url, NativeApp) {
    try {
        // The phone's browser downloads and installs the APK (or opens the store).
        await NativeApp.openExternal({ url });
    } catch {
        window.location.assign(url);
    }
}

export async function checkAndroidUpdate(config, NativeApp, doc = document) {
    const update = config?.appUpdate?.android;
    if (!update || !NativeApp) return 'none';

    const info = await NativeApp.getInfo().catch(() => ({}));
    const build = Number(info?.build ?? 0);
    const kind = updateKind(update, build);
    if (kind === 'none' || !update.url) return kind;
    if (kind === 'optional' && stored(SKIP_KEY(update.latest_code))) return 'skipped';

    const required = kind === 'required';
    const overlay = doc.createElement('div');
    overlay.className = `modal app-update-dialog${required ? ' is-required' : ''}`;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'app-update-title');
    overlay.innerHTML = html`
        <div class="modal-backdrop" ${raw(required ? '' : 'data-app-update-later')}></div>
        <div class="modal-panel app-update-panel">
            <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('download'))}</div>
            <h2 class="modal-title" id="app-update-title">${required ? 'Update required' : 'Update available'}</h2>
            <p class="modal-text">${required
                ? `This version of ${config.name ?? 'the app'} no longer works. Install version ${update.latest_name} to continue.`
                : `Version ${update.latest_name} of ${config.name ?? 'the app'} is ready${info?.version ? ` (you have ${info.version})` : ''}.`}</p>
            ${raw(update.notes ? html`<div class="app-update-notes"><strong>What's new</strong><p>${update.notes}</p></div>` : '')}
            <div class="modal-actions">
                ${raw(required ? '' : '<button type="button" class="btn btn-secondary" data-app-update-later>Later</button>')}
                <button type="button" class="btn btn-primary" data-app-update-install>${raw(icon('download'))} Update</button>
            </div>
        </div>
    `;

    overlay.addEventListener('click', (event) => {
        if (event.target.closest('[data-app-update-install]')) openDownload(update.url, NativeApp);
        if (!required && event.target.closest('[data-app-update-later]')) {
            store(SKIP_KEY(update.latest_code), '1');
            overlay.remove();
        }
    });
    // A required update can't be closed with Escape or the back button.
    if (required) {
        overlay.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') event.stopPropagation();
        }, true);
    }

    doc.body.appendChild(overlay);
    overlay.querySelector('[data-app-update-install]')?.focus();
    return kind;
}
