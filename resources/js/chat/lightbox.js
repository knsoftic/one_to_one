import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/**
 * Full-screen image preview (click an image in the chat).
 */
export function openLightbox({ src, name = '', download = '', protect = false }) {
    const previouslyFocused = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'lightbox';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', name || 'Image preview');

    overlay.innerHTML = html`
        <div class="lightbox-bar">
            <span class="lightbox-name">${name}</span>
            <span class="flex items-center gap-1">
                ${raw(download ? html`<a class="btn-icon lightbox-btn" href="${download}" download="${name}" aria-label="Download">${raw(icon('download'))}</a>` : '')}
                <button type="button" class="btn-icon lightbox-btn" data-lightbox-close aria-label="Close">${raw(icon('x'))}</button>
            </span>
        </div>
        <div class="lightbox-stage" data-lightbox-close>
            <span class="spinner lightbox-spinner"></span>
            <img class="lightbox-image" src="${src}" alt="${name}" draggable="false">
        </div>
    `;

    const image = overlay.querySelector('.lightbox-image');
    // View once (M22): no saving through the context menu.
    if (protect) overlay.addEventListener('contextmenu', (event) => event.preventDefault());
    image.addEventListener('load', () => overlay.classList.add('is-loaded'));

    image.addEventListener('click', (event) => {
        event.stopPropagation();
        const zoomed = overlay.classList.toggle('is-zoomed');
        if (zoomed) {
            const rect = image.getBoundingClientRect();
            image.style.transformOrigin = `${((event.clientX - rect.left) / rect.width) * 100}% ${((event.clientY - rect.top) / rect.height) * 100}%`;
        }
    });

    const close = () => {
        document.removeEventListener('keydown', onKey);
        overlay.classList.add('is-closing');
        setTimeout(() => overlay.remove(), 180);
        document.body.style.overflow = '';
        previouslyFocused?.focus?.();
    };

    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };

    overlay.addEventListener('click', (event) => {
        if (event.target.closest('[data-lightbox-close]') && !event.target.closest('.lightbox-image')) close();
    });

    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    overlay.querySelector('[data-lightbox-close]').focus();
}
