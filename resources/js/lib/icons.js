import icons from '../../icons/icons.json';

/**
 * Render a Lucide icon as an SVG string (same markup as the Blade <x-icon>).
 */
/** X1: arrows that point the other way in right-to-left languages. */
export const FLIP_IN_RTL = new Set(['arrow-left', 'chevron-left', 'chevron-right', 'send-horizontal', 'reply', 'forward', 'undo-2', 'log-out', 'log-in', 'corner-up-left', 'message-square-reply']);

export function icon(name, className = '') {
    const body = icons[name] ?? icons['circle-alert'];
    return `<svg class="icon ${FLIP_IN_RTL.has(name) ? 'icon-flip ' : ''}${className}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">${body}</svg>`;
}
