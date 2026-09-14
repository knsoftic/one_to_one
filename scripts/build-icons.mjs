/**
 * Generates resources/icons/icons.json from the Lucide icon set.
 *
 * The JSON map (name => inner SVG markup) is shared by the Blade <x-icon>
 * component and the JavaScript icon() helper, so icons are identical on
 * server- and client-rendered markup and no icon CDN is required.
 *
 * Usage: npm run icons
 */
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import * as lucide from 'lucide';

const ICONS = [
    'activity', 'aperture', 'archive', 'archive-restore', 'arrow-down', 'arrow-left', 'at-sign', 'ban',
    'battery-charging', 'bell', 'bell-off', 'brush', 'camera', 'chart-column', 'check', 'check-check', 'chevron-down',
    'chevron-left', 'chevron-right', 'chevron-up', 'circle-alert', 'circle-check', 'circle-dot', 'circle-pause',
    'circle-stop', 'circle-x', 'clock', 'cloud-off', 'contact', 'copy', 'corner-up-left', 'crop', 'download',
    'ellipsis', 'ellipsis-vertical', 'eraser', 'external-link', 'eye', 'eye-off', 'file', 'file-archive',
    'file-music', 'file-spreadsheet', 'file-text', 'film', 'fingerprint', 'forward', 'heart', 'heart-off',
    'hourglass', 'image', 'image-play', 'image-plus', 'info', 'key-round', 'layout-dashboard', 'link', 'list-checks',
    'list-plus', 'loader-circle', 'locate-fixed', 'lock', 'lock-keyhole', 'lock-open', 'log-out', 'mail', 'map-pin',
    'menu', 'message-circle', 'message-square-plus', 'message-square-text', 'mic', 'mic-off', 'minimize-2', 'monitor',
    'moon', 'navigation', 'notebook-pen', 'palette', 'paperclip', 'pause', 'pencil', 'phone', 'phone-incoming',
    'phone-missed', 'phone-off', 'phone-outgoing', 'pin', 'pin-off', 'play', 'plus', 'presentation', 'radio',
    'refresh-cw', 'rotate-cw', 'search', 'send-horizontal', 'settings', 'share-2', 'shield', 'shield-check',
    'signal-low', 'smile', 'smile-plus', 'sparkles', 'square', 'square-check-big', 'star', 'star-off', 'sticker',
    'sun', 'switch-camera', 'tag', 'timer', 'trash-2', 'trending-up', 'type', 'undo-2', 'upload', 'user',
    'user-check', 'user-cog', 'user-plus', 'user-round', 'user-round-plus', 'user-x', 'users', 'video', 'video-off',
    'volume-1', 'volume-2', 'vote', 'wifi', 'wifi-off', 'x', 'zoom-in',
    // Phase 3 — calls
    'gauge', 'link-2', 'maximize-2', 'monitor-up', 'monitor-x', 'picture-in-picture-2', 'signal', 'signal-high', 'signal-medium',
];

const pascal = (name) => name.replace(/(^|-)([a-z0-9])/g, (_, __, c) => c.toUpperCase());

const escapeAttr = (value) => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;');

const toMarkup = (node) =>
    node
        .map(([tag, attrs]) => {
            const attributes = Object.entries(attrs)
                .filter(([key]) => key !== 'key')
                .map(([key, value]) => `${key}="${escapeAttr(value)}"`)
                .join(' ');
            return `<${tag} ${attributes}/>`;
        })
        .join('');

const output = {};
const missing = [];

for (const name of ICONS) {
    const node = lucide[pascal(name)];
    if (!Array.isArray(node)) {
        missing.push(name);
        continue;
    }
    output[name] = toMarkup(node);
}

if (missing.length) {
    console.error(`Missing Lucide icons: ${missing.join(', ')}`);
    process.exit(1);
}

const target = resolve(dirname(fileURLToPath(import.meta.url)), '../resources/icons/icons.json');
mkdirSync(dirname(target), { recursive: true });
writeFileSync(target, JSON.stringify(output, null, 0) + '\n');
console.log(`Wrote ${Object.keys(output).length} icons to ${target}`);
