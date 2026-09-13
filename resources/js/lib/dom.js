/**
 * Small DOM and utility helpers shared across the frontend.
 */

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };

/** Escape a value for safe interpolation into HTML (text or quoted attributes). */
export function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"'`]/g, (ch) => HTML_ESCAPES[ch]);
}

/** Tagged template that escapes every interpolated value unless wrapped with raw(). */
export function html(strings, ...values) {
    return strings.reduce((out, str, i) => {
        if (i === 0) return str;
        const value = values[i - 1];
        const rendered = Array.isArray(value)
            ? value.map((v) => (v instanceof RawHtml ? v.value : escapeHtml(v))).join('')
            : value instanceof RawHtml
              ? value.value
              : escapeHtml(value);
        return out + rendered + str;
    }, '');
}

class RawHtml {
    constructor(value) {
        this.value = String(value ?? '');
    }
}

/** Mark trusted markup (already escaped / generated internally) as raw. */
export const raw = (value) => new RawHtml(value);

export function createFromHtml(markup) {
    const template = document.createElement('template');
    template.innerHTML = markup.trim();
    return template.content.firstElementChild;
}

export function debounce(fn, wait = 250) {
    let timer;
    const debounced = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
    debounced.cancel = () => clearTimeout(timer);
    return debounced;
}

export function throttle(fn, wait = 250) {
    let last = 0;
    let timer;
    return (...args) => {
        const now = Date.now();
        const remaining = wait - (now - last);
        if (remaining <= 0) {
            clearTimeout(timer);
            last = now;
            fn(...args);
        } else if (!timer) {
            timer = setTimeout(() => {
                last = Date.now();
                timer = null;
                fn(...args);
            }, remaining);
        }
    };
}

export function readJsonScript(id, fallback = {}) {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try {
        return JSON.parse(el.textContent || '{}');
    } catch {
        return fallback;
    }
}

export function formatBytes(bytes) {
    if (!bytes && bytes !== 0) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds || 0));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** Extract a human-readable error message from an axios error. */
export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
    const response = error?.response;
    if (!response) return navigator.onLine ? fallback : 'You appear to be offline.';
    if (response.status === 419) return 'Your session has expired. Please refresh the page.';
    if (response.status === 429) return 'Too many requests. Please slow down.';
    const data = response.data || {};
    if (data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    return data.message || fallback;
}
