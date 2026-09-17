/**
 * Admin → Ads editor: live preview of the sponsored card as you type, the chosen image, and the
 * "N selected" count on the countries picker.
 */
export function initAdminAds(form = document.querySelector('[data-ad-editor]'), { createUrl = (file) => URL.createObjectURL(file) } = {}) {
    if (!form) return null;

    const preview = form.querySelector('[data-ad-preview]');
    const set = (name, value) => {
        const el = preview?.querySelector(`[data-ad-preview-${name}]`);
        if (el) el.textContent = value;
    };

    const field = (name) => form.querySelector(`[data-ad-field="${name}"]`);
    const sponsor = () => (field('sponsor')?.value.trim() ? ` · ${field('sponsor').value.trim()}` : '');

    const sync = () => {
        set('title', field('title')?.value.trim() || 'Your headline');
        set('body', field('body')?.value.trim() || '');
        set('cta', field('cta')?.value.trim() || 'Learn more');
        const tag = preview?.querySelector('[data-ad-preview-tag]');
        if (tag) tag.textContent = `Sponsored${sponsor()}`;
    };

    ['title', 'body', 'cta', 'sponsor'].forEach((name) => {
        field(name)?.addEventListener('input', sync);
    });
    sync();

    // Image preview + "remove" that clears the file.
    const media = preview?.querySelector('[data-ad-preview-media]');
    const imageInput = form.querySelector('[data-ad-image]');
    const removeInput = form.querySelector('[data-ad-remove-image]');
    imageInput?.addEventListener('change', () => {
        const file = imageInput.files?.[0];
        if (file && file.type.startsWith('image/') && media) {
            media.style.backgroundImage = `url('${createUrl(file)}')`;
            if (removeInput) removeInput.checked = false;
        }
    });
    removeInput?.addEventListener('change', () => {
        if (removeInput.checked && media) {
            media.style.backgroundImage = '';
            if (imageInput) imageInput.value = '';
        }
    });

    // Countries: keep the summary button in step with the ticks.
    const countries = form.querySelector('.admin-ad-countries');
    const summary = countries?.querySelector('summary');
    const updateCount = () => {
        if (!summary) return;
        const n = countries.querySelectorAll('input[name="countries[]"]:checked').length;
        // The label is the summary's text node, between its icons.
        const textNode = [...summary.childNodes].find((node) => node.nodeType === Node.TEXT_NODE && node.textContent.trim());
        if (textNode) textNode.textContent = ` ${n ? `${n} selected` : 'All countries'} `;
    };
    countries?.addEventListener('change', updateCount);

    return { sync };
}
