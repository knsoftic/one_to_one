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
    'activity', 'arrow-down', 'arrow-left', 'at-sign', 'ban', 'battery-charging', 'bell', 'bell-off', 'camera', 'check',
    'check-check', 'contact', 'chevron-down', 'chevron-left', 'chevron-right', 'circle-alert', 'circle-check',
    'circle-pause', 'circle-x', 'clock', 'cloud-off', 'copy', 'corner-up-left', 'download', 'ellipsis-vertical',
    'external-link', 'eye', 'eye-off', 'file', 'file-text', 'image', 'info', 'key-round',
    'layout-dashboard', 'loader-circle', 'lock', 'log-out', 'mail', 'menu', 'message-circle',
    'message-square-plus', 'message-square-text', 'mic', 'monitor', 'moon', 'palette', 'paperclip',
    'pause', 'pencil', 'phone', 'play', 'refresh-cw', 'search', 'send-horizontal', 'settings',
    'shield', 'shield-check', 'signal-low', 'smile', 'sparkles', 'square', 'sun', 'trash-2', 'trending-up',
    'undo-2', 'upload', 'user', 'user-check', 'user-cog', 'user-plus', 'user-round', 'user-x',
    'users', 'volume-2', 'wifi', 'wifi-off', 'x', 'zoom-in',
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
