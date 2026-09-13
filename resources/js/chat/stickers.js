import { debounce, errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

/**
 * M17 — Stickers and GIFs in the emoji panel.
 *
 * - "Stickers": your own stickers (most recently used first), make one from a
 *   photo or from a big emoji, remove ones you no longer want.
 * - "GIFs": trending and search (only when the server has GIF search on).
 */

export const EMOJI_STICKERS = ['😂', '❤️', '👍', '🙏', '😍', '🥳', '😢', '😡', '😎', '🤔', '👏', '🔥', '💯', '🎉', '🌹', '☕', '🤲', '👋', '😘', '😴', '🤣', '😮', '💪', '✨'];

const STICKER_SIZE = 512;

/** Canvas → Blob, preferring WebP (Safari falls back to PNG; the server converts both). */
function canvasBlob(canvas) {
    return new Promise((resolve) => {
        canvas.toBlob((webp) => {
            if (webp && webp.type === 'image/webp') resolve(webp);
            else canvas.toBlob(resolve, 'image/png');
        }, 'image/webp', 0.9);
    });
}

/** A big emoji on a transparent 512×512 canvas. */
export function emojiStickerCanvas(emoji) {
    const canvas = document.createElement('canvas');
    canvas.width = STICKER_SIZE;
    canvas.height = STICKER_SIZE;
    const ctx = canvas.getContext('2d');
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.font = '380px "Apple Color Emoji", "Segoe UI Emoji", "Noto Color Emoji", sans-serif';
    ctx.fillText(emoji, STICKER_SIZE / 2, STICKER_SIZE / 2 + 24);
    return canvas;
}

/**
 * Where to draw a photo of width×height into a size×size sticker.
 * "fill" crops to a square, "fit" shows the whole photo.
 */
export function stickerPlacement(width, height, fit, size = STICKER_SIZE, padding = 0) {
    const inner = size - padding * 2;
    const scale = fit === 'fill' ? Math.max(inner / width, inner / height) : Math.min(inner / width, inner / height);
    const w = width * scale;
    const h = height * scale;
    return { x: (size - w) / 2, y: (size - h) / 2, w, h };
}

export class StickerPanel {
    constructor(chat) {
        this.chat = chat;
        this.stickers = null;
    }

    mode() {
        return { id: 'stickers', label: 'Stickers', render: (container, picker) => this.render(container, picker) };
    }

    async render(container, picker) {
        this.container = container;
        this.picker = picker;
        container.innerHTML = html`
            <div class="sticker-panel">
                <div class="sticker-actions">
                    <button type="button" class="btn btn-secondary btn-sm" data-sticker-create>${raw(icon('image-plus'))} From a photo</button>
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" data-sticker-file>
                </div>
                <div class="sticker-section-label">My stickers</div>
                <div class="sticker-grid" data-my-stickers><span class="spinner"></span></div>
                <div class="sticker-section-label">Emoji stickers</div>
                <div class="sticker-grid is-emoji">
                    ${raw(EMOJI_STICKERS.map((emoji) => html`<button type="button" class="sticker-emoji" data-emoji-sticker="${emoji}" aria-label="Send ${emoji} sticker">${emoji}</button>`).join(''))}
                </div>
            </div>
        `;

        container.onclick = (event) => this.onClick(event);
        container.querySelector('[data-sticker-file]').addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            event.target.value = '';
            if (file) this.makeFromPhoto(file);
        });

        await this.load();
    }

    async load() {
        try {
            this.stickers = (await this.chat.api.stickers()).data ?? [];
        } catch {
            this.stickers = this.stickers ?? [];
        }
        this.renderMine();
    }

    renderMine() {
        const grid = this.container?.querySelector('[data-my-stickers]');
        if (!grid) return;

        grid.innerHTML = this.stickers.length
            ? this.stickers
                .map((sticker) => html`
                    <span class="sticker-item">
                        <button type="button" class="sticker-btn" data-sticker-id="${sticker.id}" aria-label="Send sticker">
                            <img src="${sticker.url}" alt="" loading="lazy" decoding="async">
                        </button>
                        <button type="button" class="sticker-remove" data-sticker-remove="${sticker.id}" aria-label="Remove from my stickers" title="Remove">${raw(icon('x'))}</button>
                    </span>`)
                .join('')
            : html`<p class="sticker-empty">Stickers you make or save appear here.</p>`;
    }

    onClick(event) {
        const target = event.target;
        if (target.closest('[data-sticker-create]')) {
            this.container.querySelector('[data-sticker-file]').click();
        } else if (target.closest('[data-sticker-remove]')) {
            this.remove(Number(target.closest('[data-sticker-remove]').dataset.stickerRemove));
        } else if (target.closest('[data-sticker-id]')) {
            const sticker = this.stickers.find((s) => s.id === Number(target.closest('[data-sticker-id]').dataset.stickerId));
            if (sticker) this.send(sticker);
        } else if (target.closest('[data-emoji-sticker]')) {
            this.sendEmoji(target.closest('[data-emoji-sticker]').dataset.emojiSticker);
        }
    }

    send(sticker) {
        const conversationId = this.chat.active?.id;
        if (!conversationId) return;
        this.picker?.close();
        this.chat.sendSticker(conversationId, sticker);
        // Most recently used first next time.
        this.stickers = [sticker, ...this.stickers.filter((s) => s.id !== sticker.id)];
    }

    async sendEmoji(emoji) {
        const blob = await canvasBlob(emojiStickerCanvas(emoji));
        const sticker = await this.upload(blob);
        if (sticker) this.send(sticker);
    }

    async upload(blob) {
        const form = new FormData();
        form.append('sticker', blob, blob.type === 'image/webp' ? 'sticker.webp' : 'sticker.png');
        try {
            return await this.chat.api.createSticker(form);
        } catch (error) {
            toast.error(errorMessage(error, 'The sticker could not be made.'));
            return null;
        }
    }

    async remove(id) {
        const choice = await confirmDialog({
            title: 'Remove this sticker?',
            message: 'It is removed from your stickers. Messages you already sent keep it.',
            icon: 'trash-2',
            actions: [{ label: 'Remove', value: 'remove', variant: 'danger' }],
        });
        if (choice !== 'remove') return;

        try {
            await this.chat.api.deleteSticker(id);
            this.stickers = this.stickers.filter((s) => s.id !== id);
            this.renderMine();
        } catch (error) {
            toast.error(errorMessage(error, 'The sticker could not be removed.'));
        }
    }

    async makeFromPhoto(file) {
        const result = await openStickerMaker(file);
        if (!result) return;
        const sticker = await this.upload(result);
        if (sticker) this.send(sticker);
    }
}

/**
 * Small editor: fill (square crop) or fit, square / rounded / circle shape,
 * optional white outline. Resolves with a 512×512 image Blob or null.
 */
export async function openStickerMaker(file) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        toast.error('This photo cannot be used for a sticker.');
        return null;
    }

    return new Promise((resolve) => {
        const state = { fit: 'fill', shape: 'rounded', outline: true };
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal sticker-maker';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Make a sticker');

        const option = (group, value, label) =>
            html`<button type="button" class="chip" data-sticker-option="${group}" data-value="${value}" aria-pressed="false">${label}</button>`;

        overlay.innerHTML = html`
            <div class="modal-backdrop" data-sticker-cancel></div>
            <div class="modal-panel sticker-maker-panel">
                <h2 class="modal-title">Make a sticker</h2>
                <canvas class="sticker-maker-canvas" width="${STICKER_SIZE}" height="${STICKER_SIZE}" data-sticker-canvas></canvas>
                <div class="sticker-maker-options">
                    <div class="sticker-maker-row">${raw(option('fit', 'fill', 'Fill') + option('fit', 'fit', 'Whole photo'))}</div>
                    <div class="sticker-maker-row">${raw(option('shape', 'square', 'Square') + option('shape', 'rounded', 'Rounded') + option('shape', 'circle', 'Circle'))}</div>
                    <div class="sticker-maker-row">${raw(option('outline', 'on', 'White outline') + option('outline', 'off', 'No outline'))}</div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-sticker-cancel>Cancel</button>
                    <button type="button" class="btn btn-primary" data-sticker-done>Send sticker</button>
                </div>
            </div>
        `;

        const canvas = overlay.querySelector('[data-sticker-canvas]');
        const draw = () => {
            const ctx = canvas.getContext('2d');
            const outline = state.outline ? 18 : 0;
            const inset = outline + 6;
            const radius = { square: 0, rounded: 90, circle: STICKER_SIZE / 2 }[state.shape];
            const clip = (pad) => {
                ctx.beginPath();
                const size = STICKER_SIZE - pad * 2;
                ctx.roundRect(pad, pad, size, size, Math.max(0, Math.min(size / 2, radius - pad / 2)));
            };

            ctx.clearRect(0, 0, STICKER_SIZE, STICKER_SIZE);

            if (state.outline) {
                ctx.save();
                clip(6);
                ctx.fillStyle = '#ffffff';
                ctx.shadowColor = 'rgba(0, 0, 0, 0.25)';
                ctx.shadowBlur = 8;
                ctx.fill();
                ctx.restore();
            }

            ctx.save();
            clip(inset);
            ctx.clip();
            const place = stickerPlacement(bitmap.width, bitmap.height, state.fit, STICKER_SIZE, inset);
            ctx.drawImage(bitmap, place.x, place.y, place.w, place.h);
            ctx.restore();

            overlay.querySelectorAll('[data-sticker-option]').forEach((button) => {
                const { stickerOption: group, value } = button.dataset;
                const on = group === 'outline' ? (value === 'on') === state.outline : state[group] === value;
                button.classList.toggle('is-active', on);
                button.setAttribute('aria-pressed', String(on));
            });
        };

        const close = (result) => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            bitmap.close?.();
            previouslyFocused?.focus?.();
            resolve(result);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close(null);
        };

        overlay.addEventListener('click', async (event) => {
            const choice = event.target.closest('[data-sticker-option]');
            if (choice) {
                const { stickerOption: group, value } = choice.dataset;
                state[group] = group === 'outline' ? value === 'on' : value;
                draw();
            } else if (event.target.closest('[data-sticker-cancel]')) {
                close(null);
            } else if (event.target.closest('[data-sticker-done]')) {
                close(await canvasBlob(canvas));
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        draw();
        overlay.querySelector('[data-sticker-done]').focus();
    });
}

export class GifPanel {
    constructor(chat) {
        this.chat = chat;
        this.request = 0;
    }

    mode() {
        return { id: 'gifs', label: 'GIFs', render: (container, picker) => this.render(container, picker) };
    }

    render(container, picker) {
        this.container = container;
        this.picker = picker;
        container.innerHTML = html`
            <div class="gif-panel">
                <div class="input-wrap gif-search">
                    ${raw(icon('search'))}
                    <input type="search" class="form-control" placeholder="Search GIFs" maxlength="50" aria-label="Search GIFs" data-gif-query>
                </div>
                <div class="gif-grid" data-gif-grid><span class="spinner"></span></div>
                <div class="gif-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-gif-more hidden>More</button>
                    <span class="gif-credit">Powered by Tenor</span>
                </div>
            </div>
        `;

        const input = container.querySelector('[data-gif-query]');
        input.addEventListener('input', debounce(() => this.search(input.value.trim()), 350));
        container.onclick = (event) => {
            const gif = event.target.closest('[data-gif-id]');
            if (gif) this.send(this.results.find((g) => g.id === gif.dataset.gifId));
            else if (event.target.closest('[data-gif-more]')) this.search(this.query, this.next);
        };

        this.results = [];
        this.search('');
        setTimeout(() => input.focus(), 0);
    }

    async search(query, position = null) {
        const request = ++this.request;
        this.query = query;
        const grid = this.container.querySelector('[data-gif-grid]');
        const more = this.container.querySelector('[data-gif-more]');
        if (!position) grid.innerHTML = '<span class="spinner"></span>';

        try {
            const page = await this.chat.api.gifs(query, position);
            if (request !== this.request) return;

            this.results = position ? [...this.results, ...page.results] : page.results;
            this.next = page.next;
            more.hidden = !page.next || !page.results.length;
            grid.innerHTML = this.results.length
                ? this.results
                    .map((gif) => html`<button type="button" class="gif-item" data-gif-id="${gif.id}" title="${gif.title}" aria-label="Send GIF: ${gif.title}"
                        style="aspect-ratio: ${Number(gif.width) || 1} / ${Number(gif.height) || 1}"><img src="${gif.preview_url}" alt="" loading="lazy" decoding="async"></button>`)
                    .join('')
                : html`<p class="sticker-empty">No GIFs found.</p>`;
        } catch (error) {
            if (request !== this.request) return;
            grid.innerHTML = html`<p class="sticker-empty">${errorMessage(error, 'GIF search is not available right now.')}</p>`;
        }
    }

    send(gif) {
        const conversationId = this.chat.active?.id;
        if (!gif || !conversationId) return;
        this.picker?.close();
        this.chat.sendGif(conversationId, gif);
    }
}
