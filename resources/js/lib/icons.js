import icons from '../../icons/icons.json';

/**
 * Render a Lucide icon as an SVG string (same markup as the Blade <x-icon>).
 */
export function icon(name, className = '') {
    const body = icons[name] ?? icons['circle-alert'];
    return `<svg class="icon ${className}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${body}</svg>`;
}
