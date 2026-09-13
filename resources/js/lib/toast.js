import { html, raw } from './dom';
import { icon } from './icons';

const ICONS = { success: 'circle-check', error: 'circle-alert', warning: 'circle-alert', info: 'info' };
const MAX_TOASTS = 4;

function stack() {
    let el = document.getElementById('toast-stack');
    if (!el) {
        el = document.createElement('div');
        el.id = 'toast-stack';
        el.className = 'toast-stack';
        document.body.appendChild(el);
    }
    return el;
}

function dismiss(el) {
    if (!el || el.classList.contains('is-leaving')) return;
    el.classList.add('is-leaving');
    el.addEventListener('animationend', () => el.remove(), { once: true });
    setTimeout(() => el.remove(), 400);
}

/**
 * Show a toast notification.
 *
 * @param {string} message
 * @param {{type?: 'success'|'error'|'warning'|'info', title?: string, timeout?: number, avatar?: string, onClick?: Function}} options
 */
export function toast(message, options = {}) {
    const { type = 'info', title = '', timeout = 4500, avatar = '', onClick = null } = options;
    const container = stack();

    while (container.children.length >= MAX_TOASTS) {
        container.firstElementChild.remove();
    }

    const el = document.createElement('div');
    el.className = `toast toast-${type}${onClick ? ' is-clickable' : ''}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = html`
        ${avatar ? raw(avatar) : raw(`<div class="toast-icon">${icon(ICONS[type] ?? 'info')}</div>`)}
        <div class="toast-body">
            ${title ? raw(html`<div class="toast-title">${title}</div>`) : ''}
            <div class="toast-message">${message}</div>
        </div>
        <button type="button" class="btn-icon btn-icon-sm toast-close" aria-label="Dismiss">${raw(icon('x'))}</button>
    `;

    el.querySelector('.toast-close').addEventListener('click', (event) => {
        event.stopPropagation();
        dismiss(el);
    });

    if (onClick) {
        el.addEventListener('click', () => {
            onClick();
            dismiss(el);
        });
    }

    container.appendChild(el);

    if (timeout > 0) {
        let timer = setTimeout(() => dismiss(el), timeout);
        el.addEventListener('mouseenter', () => clearTimeout(timer));
        el.addEventListener('mouseleave', () => (timer = setTimeout(() => dismiss(el), 1500)));
    }

    return el;
}

toast.success = (message, options = {}) => toast(message, { ...options, type: 'success' });
toast.error = (message, options = {}) => toast(message, { ...options, type: 'error', timeout: options.timeout ?? 6000 });
toast.info = (message, options = {}) => toast(message, { ...options, type: 'info' });
toast.warning = (message, options = {}) => toast(message, { ...options, type: 'warning' });
