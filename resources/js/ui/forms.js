import { $, $$ } from '../lib/dom';
import { toast } from '../lib/toast';

const AVATAR_MAX_BYTES = 2 * 1024 * 1024;
const AVATAR_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

/** Show / hide password fields. */
function initPasswordToggles() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-password-toggle]');
        if (!button) return;
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        $('[data-show]', button).toggleAttribute('hidden', show);
        $('[data-hide]', button).toggleAttribute('hidden', !show);
    });
}

/** Instant preview for profile image inputs, with client-side checks. */
function initAvatarPickers() {
    $$('[data-avatar-picker]').forEach((picker) => {
        const input = $('[data-avatar-input]', picker);
        const preview = $('[data-avatar-preview]', picker);
        const image = $('[data-avatar-image]', picker);
        const placeholder = $('[data-avatar-placeholder]', picker);
        const clear = $('[data-avatar-clear]', picker);
        const remove = $('[data-avatar-remove]', picker);
        const submit = $('[data-photo-submit]', picker);
        let objectUrl = null;

        const show = (src) => {
            image.src = src;
            image.hidden = false;
            placeholder?.setAttribute('hidden', '');
            preview.classList.add('has-image');
            clear?.removeAttribute('hidden');
            if (submit) submit.disabled = false;
        };

        const reset = () => {
            image.removeAttribute('src');
            image.hidden = true;
            placeholder?.removeAttribute('hidden');
            preview.classList.remove('has-image');
            clear?.setAttribute('hidden', '');
            if (submit) submit.disabled = true;
        };

        input.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;

            if (!AVATAR_TYPES.includes(file.type)) {
                toast.error('Please choose a JPG, PNG or WEBP image.');
                input.value = '';
                return;
            }
            if (file.size > AVATAR_MAX_BYTES) {
                toast.error('The profile image must not be larger than 2 MB.');
                input.value = '';
                return;
            }

            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(file);
            show(objectUrl);
            if (remove) remove.value = '0';
        });

        clear?.addEventListener('click', () => {
            input.value = '';
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            reset();
            if (remove) remove.value = '1';
        });
    });
}

/** Lightweight password strength indicator (0–4). */
function initStrengthMeters() {
    $$('[data-strength-input]').forEach((input) => {
        const meter = input.closest('.form-group')?.querySelector('[data-strength-meter]');
        if (!meter) return;

        input.addEventListener('input', () => {
            const value = input.value;
            let score = 0;
            if (value.length >= 8) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value) || value.length >= 14) score++;
            meter.dataset.score = value ? String(Math.max(1, score)) : '0';
        });
    });
}

/** Disable submit buttons and show a spinner while a form is submitting. */
function initLoadingForms() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('[data-loading-form]')) return;

        const button = form.querySelector('button[type="submit"]');
        if (!button || button.classList.contains('is-loading')) {
            if (button) event.preventDefault();
            return;
        }

        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        const spinner = document.createElement('span');
        spinner.className = 'spinner';
        button.prepend(spinner);
        button.querySelector('svg.icon')?.setAttribute('hidden', '');
    });

    // Restore buttons when navigating back (bfcache).
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        $$('button.is-loading').forEach((button) => {
            button.classList.remove('is-loading');
            button.removeAttribute('aria-busy');
            button.querySelector('.spinner')?.remove();
            button.querySelector('svg.icon')?.removeAttribute('hidden');
        });
    });
}

/** Suggest a username from the full name until the user edits it. */
function initUsernameSuggestions() {
    $$('[data-username-suggest]').forEach((form) => {
        const source = $('[data-suggest-source]', form);
        const target = $('[data-suggest-target]', form);
        if (!source || !target) return;

        let edited = target.value.trim() !== '';
        target.addEventListener('input', () => {
            edited = target.value.trim() !== '';
        });

        source.addEventListener('input', () => {
            if (edited) return;
            target.value = source.value
                .normalize('NFKD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '.')
                .replace(/^\.+/, '')
                .slice(0, 30)
                .replace(/\.+$/, '');
        });
    });
}

export function initForms() {
    initUsernameSuggestions();
    initPasswordToggles();
    initAvatarPickers();
    initStrengthMeters();
    initLoadingForms();
}
