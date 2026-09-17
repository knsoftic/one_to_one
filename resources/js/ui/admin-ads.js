/**
 * Admin → Ads editor: a live preview of the card for every placement it can run in, the chosen
 * image, and the "N selected" count on the countries picker.
 */
export function initAdminAds(form = document.querySelector('[data-ad-editor]'), { createUrl = (file) => URL.createObjectURL(file) } = {}) {
    if (!form) return null;

    const panes = [...form.querySelectorAll('[data-ad-preview]')];
    const set = (name, value) => {
        for (const pane of panes) {
            const el = pane.querySelector(`[data-ad-preview-${name}]`);
            if (el) el.textContent = value;
        }
    };

    const field = (name) => form.querySelector(`[data-ad-field="${name}"]`);
    const sponsor = () => (field('sponsor')?.value.trim() ? ` · ${field('sponsor').value.trim()}` : '');

    const sync = () => {
        set('title', field('title')?.value.trim() || 'Your headline');
        set('body', field('body')?.value.trim() || '');
        set('cta', field('cta')?.value.trim() || 'Learn more');
        set('tag', `Sponsored${sponsor()}`);
    };

    ['title', 'body', 'cta', 'sponsor'].forEach((name) => {
        field(name)?.addEventListener('input', sync);
    });
    sync();

    // One placement at a time, so each card is shown at the size it really appears at.
    const tabs = form.querySelector('[data-ad-preview-tabs]');
    tabs?.addEventListener('click', (event) => {
        const tab = event.target.closest('[data-preview-tab]');
        if (!tab) return;
        for (const other of tabs.querySelectorAll('[data-preview-tab]')) {
            const active = other === tab;
            other.classList.toggle('is-active', active);
            other.setAttribute('aria-selected', active ? 'true' : 'false');
        }
        for (const pane of form.querySelectorAll('[data-preview-pane]')) {
            pane.hidden = pane.dataset.previewPane !== tab.dataset.previewTab;
        }
    });

    // Image preview + "remove" that clears the file.
    const media = () => panes.map((pane) => pane.querySelector('[data-ad-preview-media]')).filter(Boolean);
    const imageInput = form.querySelector('[data-ad-image]');
    const removeInput = form.querySelector('[data-ad-remove-image]');
    imageInput?.addEventListener('change', () => {
        const file = imageInput.files?.[0];
        if (file && file.type.startsWith('image/')) {
            const url = createUrl(file);
            for (const el of media()) el.style.backgroundImage = `url('${url}')`;
            if (removeInput) removeInput.checked = false;
        }
    });
    removeInput?.addEventListener('change', () => {
        if (removeInput.checked) {
            for (const el of media()) el.style.backgroundImage = '';
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
