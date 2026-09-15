import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/** D2 — wallpaper choices (colours come from chat-tools.css, light and dark). */
export const WALLPAPERS = [
    { key: 'default', label: 'Default' },
    { key: 'plain', label: 'Plain' },
    { key: 'lavender', label: 'Lavender' },
    { key: 'mint', label: 'Mint' },
    { key: 'sky', label: 'Sky' },
    { key: 'peach', label: 'Peach' },
    { key: 'rose', label: 'Rose' },
    { key: 'sand', label: 'Sand' },
    { key: 'slate', label: 'Slate' },
    { key: 'aurora', label: 'Aurora' },
    { key: 'sunset', label: 'Sunset' },
    { key: 'ocean', label: 'Ocean' },
    { key: 'forest', label: 'Forest' },
    { key: 'midnight', label: 'Midnight' },
];

export const MAX_DIM = 80;

export function wallpaperLabel(wallpaper) {
    if (wallpaper?.key === 'custom') return 'Your photo';
    return WALLPAPERS.find((item) => item.key === wallpaper?.key)?.label ?? 'Default';
}

/** Paint an element (the chat area or a preview) with a wallpaper. */
export function applyWallpaper(el, wallpaper, dim = 0) {
    if (!el) return;
    const custom = wallpaper?.key === 'custom' && wallpaper.url;
    const key = custom || WALLPAPERS.some((item) => item.key === wallpaper?.key) ? wallpaper.key : 'default';

    el.classList.add('chat-wallpaper');
    el.dataset.wallpaper = key;
    if (custom) el.style.setProperty('--wallpaper-photo', `url(${JSON.stringify(String(wallpaper.url))})`);
    else el.style.removeProperty('--wallpaper-photo');
    el.style.setProperty('--wallpaper-dim', String(Math.max(0, Math.min(MAX_DIM, Number(dim) || 0)) / 100));
}

/**
 * Wallpaper picker dialog.
 *
 * @param {object} options
 * @param {string} options.title
 * @param {{key: string, url?: string}|null} options.current
 * @param {string} [options.defaultLabel] label of the "default" choice
 * @param {number|null} [options.dim] dark theme dimming (null = not offered)
 * @param {(form: FormData) => Promise<unknown>} options.onSave
 */
export function openWallpaperPicker({ title = 'Chat wallpaper', current = null, defaultLabel = 'Default', dim = null, onSave }) {
    const previouslyFocused = document.activeElement;
    let selected = current?.key ?? 'default';
    let photo = null;
    let photoUrl = current?.key === 'custom' ? current.url : null;
    let objectUrl = null;

    const overlay = document.createElement('div');
    overlay.className = 'modal wallpaper-dialog';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'wallpaper-title');
    overlay.innerHTML = html`
        <div class="modal-backdrop" data-wallpaper-cancel></div>
        <form class="modal-panel wallpaper-panel" data-wallpaper-form>
            <h2 class="modal-title" id="wallpaper-title">${title}</h2>
            <div class="wallpaper-preview" data-wallpaper-preview aria-hidden="true">
                <span class="wallpaper-preview-day">Today</span>
                <span class="wallpaper-preview-bubble">Hi! How does this look?</span>
                <span class="wallpaper-preview-bubble is-mine">Looks great 👍</span>
            </div>
            <div class="wallpaper-grid" role="radiogroup" aria-label="Wallpaper">
                ${raw(WALLPAPERS.map((item) => html`
                    <label class="wallpaper-swatch" title="${item.key === 'default' ? defaultLabel : item.label}">
                        <input type="radio" name="wallpaper" value="${item.key}" ${raw(item.key === selected ? 'checked' : '')}>
                        <span class="wallpaper-swatch-art chat-wallpaper" data-wallpaper="${item.key}">${raw(icon('check'))}</span>
                        <span class="wallpaper-swatch-label">${item.key === 'default' ? defaultLabel : item.label}</span>
                    </label>`).join(''))}
                <label class="wallpaper-swatch is-photo" title="Your photo">
                    <input type="radio" name="wallpaper" value="custom" ${raw(selected === 'custom' ? 'checked' : '')} ${raw(photoUrl ? '' : 'disabled')} data-wallpaper-custom>
                    <span class="wallpaper-swatch-art chat-wallpaper" data-wallpaper-photo-art>${raw(icon('image-plus'))}</span>
                    <span class="wallpaper-swatch-label">Your photo</span>
                </label>
            </div>
            <input type="file" accept="image/jpeg,image/png,image/webp" hidden data-wallpaper-file>
            <button type="button" class="btn btn-secondary btn-sm wallpaper-upload" data-wallpaper-upload>${raw(icon('upload'))} Choose a photo</button>
            ${raw(dim === null ? '' : html`
                <label class="wallpaper-dim">
                    <span><strong>Dim in dark theme</strong><small data-wallpaper-dim-value>${Number(dim) || 0}%</small></span>
                    <input type="range" name="dim" min="0" max="${MAX_DIM}" step="5" value="${Number(dim) || 0}">
                </label>`)}
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" data-wallpaper-cancel>Cancel</button>
                <button type="submit" class="btn btn-primary" data-wallpaper-save>Save</button>
            </div>
        </form>
    `;

    const preview = overlay.querySelector('[data-wallpaper-preview]');
    const photoArt = overlay.querySelector('[data-wallpaper-photo-art]');
    const customInput = overlay.querySelector('[data-wallpaper-custom]');
    const fileInput = overlay.querySelector('[data-wallpaper-file]');
    const dimInput = overlay.querySelector('input[name="dim"]');

    const refresh = () => {
        const dimValue = dimInput ? Number(dimInput.value) : 0;
        applyWallpaper(preview, selected === 'custom' ? { key: 'custom', url: photoUrl } : { key: selected }, dimValue);
        if (photoUrl) {
            applyWallpaper(photoArt, { key: 'custom', url: photoUrl });
            photoArt.classList.add('has-photo');
        }
        const value = overlay.querySelector('[data-wallpaper-dim-value]');
        if (value) value.textContent = `${dimValue}%`;
    };

    const close = () => {
        document.removeEventListener('keydown', onKey);
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        overlay.remove();
        previouslyFocused?.focus?.();
    };
    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };

    overlay.addEventListener('click', (event) => {
        if (event.target.closest('[data-wallpaper-cancel]')) close();
        if (event.target.closest('[data-wallpaper-upload]')) fileInput.click();
        // The photo tile opens the file picker until a photo is chosen.
        if (event.target.closest('.wallpaper-swatch.is-photo') && customInput.disabled) {
            event.preventDefault();
            fileInput.click();
        }
    });
    overlay.addEventListener('change', (event) => {
        if (event.target.name === 'wallpaper') {
            selected = event.target.value;
            refresh();
        }
    });
    dimInput?.addEventListener('input', refresh);

    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        photo = file;
        objectUrl = URL.createObjectURL(file);
        photoUrl = objectUrl;
        customInput.disabled = false;
        customInput.checked = true;
        selected = 'custom';
        refresh();
    });

    overlay.querySelector('[data-wallpaper-form]').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData();
        form.append('wallpaper', selected);
        if (selected === 'custom' && photo) form.append('photo', photo);
        if (dimInput) form.append('dim', dimInput.value);

        const save = overlay.querySelector('[data-wallpaper-save]');
        save.disabled = true;
        save.classList.add('is-loading');
        try {
            await onSave(form);
            close();
        } catch {
            // onSave shows the error; the dialog stays open to try again.
            save.disabled = false;
            save.classList.remove('is-loading');
        }
    });

    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
    refresh();
    (overlay.querySelector('input[name="wallpaper"]:checked') ?? overlay.querySelector('input[name="wallpaper"]'))?.focus();

    return { close };
}
