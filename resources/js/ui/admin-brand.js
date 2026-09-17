/**
 * Admin → App settings → App name & icon: preview the name, a new icon and its background
 * before saving (a square icon, and the circle Android phones cut it into).
 */
export function initAdminBrand(form = document.querySelector('[data-brand-form]'), { createUrl = (file) => URL.createObjectURL(file) } = {}) {
    if (!form) return null;

    const preview = form.querySelector('[data-brand-preview]');
    const nameInput = form.querySelector('[data-brand-name]');
    const iconInput = form.querySelector('[data-brand-icon]');
    const colorInput = form.querySelector('[data-brand-color]');
    const removeInput = form.querySelector('[data-brand-remove]');
    const images = [...form.querySelectorAll('[data-brand-image]')];
    const originals = images.map((image) => image.getAttribute('src'));

    const showName = () => {
        const name = nameInput?.value.trim() || nameInput?.defaultValue || '';
        form.querySelectorAll('[data-brand-label]').forEach((label) => {
            label.textContent = name;
        });
    };

    const showIcon = () => {
        const file = iconInput?.files?.[0];
        if (file && file.type.startsWith('image/')) {
            const url = createUrl(file);
            images.forEach((image) => {
                image.src = url;
                // The phone's circle: the logo sits inside the safe zone on the background colour.
                image.closest('.admin-brand-tile')?.classList.toggle('is-padded', image.hasAttribute('data-brand-padded'));
            });
            if (removeInput) removeInput.checked = false;
            return;
        }
        images.forEach((image, index) => {
            image.src = originals[index];
            image.closest('.admin-brand-tile')?.classList.remove('is-padded');
        });
    };

    const showColor = () => {
        if (colorInput?.value) preview?.style.setProperty('--brand-color', colorInput.value);
    };

    nameInput?.addEventListener('input', showName);
    iconInput?.addEventListener('change', showIcon);
    colorInput?.addEventListener('input', showColor);
    removeInput?.addEventListener('change', () => {
        if (removeInput.checked && iconInput) {
            iconInput.value = '';
            showIcon();
        }
    });

    return { showName, showIcon, showColor };
}
