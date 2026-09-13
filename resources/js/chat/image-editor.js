import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * M14 — Edit a photo before sending: crop, rotate, draw and add text.
 *
 * Edits are kept as a list of operations replayed on the original (so undo is
 * exact); every operation stores coordinates in the pixels of the image as it
 * was when the operation was made.
 */

/** Working size: the server stores photos up to 2560 px anyway. */
export const MAX_EDGE = 2560;
export const COLORS = ['#ffffff', '#111827', '#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7'];
/** Brush and text sizes as a share of the image's longest edge. */
export const SIZES = { S: 0.006, M: 0.012, L: 0.024 };
const TEXT_SIZE = 0.06;
const MIN_CROP = 32;

/* ------------------------------------------------------------------ */
/* Geometry (pure, unit tested)                                        */
/* ------------------------------------------------------------------ */

/** Keep a rectangle inside the image and at least `min` px in size. */
export function clampRect({ x, y, w, h }, width, height, min = MIN_CROP) {
    w = Math.max(min, Math.min(w, width));
    h = Math.max(min, Math.min(h, height));
    x = Math.max(0, Math.min(x, width - w));
    y = Math.max(0, Math.min(y, height - h));

    return { x, y, w, h };
}

/**
 * Move a crop rectangle ("move") or drag one of its corners ("nw", "ne", "sw", "se")
 * by dx/dy image pixels, staying inside the image.
 */
export function adjustRect(rect, handle, dx, dy, width, height, min = MIN_CROP) {
    if (handle === 'move') {
        return clampRect({ ...rect, x: rect.x + dx, y: rect.y + dy }, width, height, min);
    }

    let left = rect.x;
    let top = rect.y;
    let right = rect.x + rect.w;
    let bottom = rect.y + rect.h;

    if (handle.includes('w')) left = Math.min(Math.max(0, left + dx), right - min);
    if (handle.includes('e')) right = Math.max(Math.min(width, right + dx), left + min);
    if (handle.includes('n')) top = Math.min(Math.max(0, top + dy), bottom - min);
    if (handle.includes('s')) bottom = Math.max(Math.min(height, bottom + dy), top + min);

    return { x: left, y: top, w: right - left, h: bottom - top };
}

/** Size after `turns` quarter turns. */
export function rotatedSize(width, height, turns) {
    return turns % 2 === 0 ? { width, height } : { width: height, height: width };
}

/** File name and type of the edited photo (PNG stays PNG unless too large). */
export function outputFormat(file, pngTooLarge = false) {
    const png = file.type === 'image/png' && !pngTooLarge;
    const base = String(file.name || 'photo').replace(/\.[^.]+$/, '') || 'photo';

    return { type: png ? 'image/png' : 'image/jpeg', name: `${base}.${png ? 'png' : 'jpg'}` };
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

function canvasOf(width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width));
    canvas.height = Math.max(1, Math.round(height));
    return canvas;
}

async function loadImage(file) {
    let source;
    try {
        source = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        source = await new Promise((resolve, reject) => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = url;
        });
    }

    const width = source.width || source.naturalWidth;
    const height = source.height || source.naturalHeight;
    const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
    const canvas = canvasOf(width * scale, height * scale);
    canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
    source.close?.();

    return canvas;
}

function drawStroke(ctx, { points, color, size }) {
    if (!points.length) return;
    ctx.save();
    ctx.strokeStyle = color;
    ctx.fillStyle = color;
    ctx.lineWidth = size;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';

    if (points.length === 1) {
        ctx.beginPath();
        ctx.arc(points[0][0], points[0][1], size / 2, 0, Math.PI * 2);
        ctx.fill();
    } else {
        ctx.beginPath();
        ctx.moveTo(points[0][0], points[0][1]);
        for (let i = 1; i < points.length; i++) ctx.lineTo(points[i][0], points[i][1]);
        ctx.stroke();
    }
    ctx.restore();
}

function isLight(hex) {
    const n = parseInt(hex.slice(1), 16);
    return ((n >> 16) & 255) * 0.299 + ((n >> 8) & 255) * 0.587 + (n & 255) * 0.114 > 150;
}

function drawText(ctx, { x, y, text, color, size }) {
    ctx.save();
    ctx.font = `700 ${size}px system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.lineJoin = 'round';
    ctx.lineWidth = Math.max(2, size * 0.14);
    ctx.strokeStyle = isLight(color) ? 'rgba(0, 0, 0, 0.75)' : 'rgba(255, 255, 255, 0.85)';
    ctx.strokeText(text, x, y);
    ctx.fillStyle = color;
    ctx.fillText(text, x, y);
    ctx.restore();
}

/** Replay the operations on a copy of the original. */
function render(base, ops) {
    let canvas = canvasOf(base.width, base.height);
    canvas.getContext('2d').drawImage(base, 0, 0);

    for (const op of ops) {
        if (op.type === 'rotate') {
            const size = rotatedSize(canvas.width, canvas.height, 1);
            const next = canvasOf(size.width, size.height);
            const ctx = next.getContext('2d');
            ctx.translate(next.width / 2, next.height / 2);
            ctx.rotate(Math.PI / 2);
            ctx.drawImage(canvas, -canvas.width / 2, -canvas.height / 2);
            canvas = next;
        } else if (op.type === 'crop') {
            const { x, y, w, h } = op.rect;
            const next = canvasOf(w, h);
            next.getContext('2d').drawImage(canvas, x, y, w, h, 0, 0, next.width, next.height);
            canvas = next;
        } else if (op.type === 'stroke') {
            drawStroke(canvas.getContext('2d'), op);
        } else if (op.type === 'text') {
            drawText(canvas.getContext('2d'), op);
        }
    }

    return canvas;
}

/* ------------------------------------------------------------------ */
/* Editor                                                              */
/* ------------------------------------------------------------------ */

/**
 * Open the editor for a photo. Resolves with the edited File, the original
 * when nothing was changed, or null when cancelled.
 */
export async function openImageEditor(file, { maxBytes = Infinity } = {}) {
    let base;
    try {
        base = await loadImage(file);
    } catch {
        toast.error('This photo cannot be edited.');
        return null;
    }

    return new Promise((resolve) => new ImageEditor(file, base, { maxBytes, resolve }).open());
}

class ImageEditor {
    constructor(file, base, { maxBytes, resolve }) {
        this.file = file;
        this.base = base;
        this.maxBytes = maxBytes;
        this.resolve = resolve;
        this.ops = [];
        this.tool = 'draw';
        this.color = COLORS[2];
        this.size = 'M';
        this.result = base;
        this.crop = null;
        this.drag = null;
        this.stroke = null;
        this.previouslyFocused = document.activeElement;
        this.onKey = (event) => this.handleKey(event);
    }

    open() {
        const tool = (name, iconName, label) =>
            html`<button type="button" class="image-editor-tool" data-editor-tool="${name}" aria-label="${label}" title="${label}" aria-pressed="false">${raw(icon(iconName))}</button>`;

        this.overlay = document.createElement('div');
        this.overlay.className = 'image-editor';
        this.overlay.setAttribute('role', 'dialog');
        this.overlay.setAttribute('aria-modal', 'true');
        this.overlay.setAttribute('aria-label', 'Edit photo');
        this.overlay.innerHTML = html`
            <div class="image-editor-bar">
                <button type="button" class="btn-icon image-editor-btn" data-editor-cancel aria-label="Cancel editing" title="Cancel">${raw(icon('x'))}</button>
                <div class="image-editor-tools" role="toolbar" aria-label="Editing tools">
                    ${raw(tool('crop', 'crop', 'Crop'))}
                    <button type="button" class="image-editor-tool" data-editor-rotate aria-label="Rotate" title="Rotate">${raw(icon('rotate-cw'))}</button>
                    ${raw(tool('draw', 'brush', 'Draw'))}
                    ${raw(tool('text', 'type', 'Add text'))}
                    <button type="button" class="image-editor-tool" data-editor-undo aria-label="Undo" title="Undo" disabled>${raw(icon('undo-2'))}</button>
                </div>
                <button type="button" class="btn btn-primary btn-sm" data-editor-done>Done</button>
            </div>
            <div class="image-editor-stage">
                <div class="image-editor-wrap" data-editor-wrap>
                    <canvas class="image-editor-canvas" data-editor-canvas></canvas>
                    <div class="image-editor-crop" data-editor-crop hidden>
                        <span data-handle="nw"></span><span data-handle="ne"></span><span data-handle="sw"></span><span data-handle="se"></span>
                    </div>
                </div>
            </div>
            <div class="image-editor-options" data-editor-options></div>
        `;

        this.canvas = this.overlay.querySelector('[data-editor-canvas]');
        this.wrap = this.overlay.querySelector('[data-editor-wrap]');
        this.cropBox = this.overlay.querySelector('[data-editor-crop]');
        this.options = this.overlay.querySelector('[data-editor-options]');

        this.bind();
        document.body.appendChild(this.overlay);
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', this.onKey);

        this.redraw();
        this.setTool('draw');
        this.overlay.querySelector('[data-editor-done]').focus();
    }

    bind() {
        this.overlay.addEventListener('click', (event) => {
            const target = event.target;
            if (target.closest('[data-editor-cancel]')) this.close(null);
            else if (target.closest('[data-editor-done]')) this.finish();
            else if (target.closest('[data-editor-rotate]')) this.push({ type: 'rotate' });
            else if (target.closest('[data-editor-undo]')) this.undo();
            else if (target.closest('[data-editor-tool]')) this.setTool(target.closest('[data-editor-tool]').dataset.editorTool);
            else if (target.closest('[data-color]')) this.setColor(target.closest('[data-color]').dataset.color);
            else if (target.closest('[data-size]')) this.setSize(target.closest('[data-size]').dataset.size);
            else if (target.closest('[data-crop-apply]')) this.applyCrop();
            else if (target.closest('[data-crop-reset]')) this.startCrop();
        });

        // Picking a colour while typing text keeps the text box focused.
        this.options.addEventListener('mousedown', (event) => {
            if (this.pendingText && event.target.closest('button')) event.preventDefault();
        });

        this.canvas.addEventListener('pointerdown', (event) => this.pointerDown(event));
        this.cropBox.addEventListener('pointerdown', (event) => this.cropPointerDown(event));
        this.overlay.addEventListener('pointermove', (event) => this.pointerMove(event));
        this.overlay.addEventListener('pointerup', (event) => this.pointerUp(event));
        this.overlay.addEventListener('pointercancel', (event) => this.pointerUp(event));
        window.addEventListener('resize', (this.onResize = () => this.positionCrop()));
    }

    handleKey(event) {
        if (event.target.closest?.('.image-editor-text-input')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            if (this.tool === 'crop') this.setTool('draw');
            else this.close(null);
        } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            this.undo();
        }
    }

    /* ---- state ---- */

    push(op) {
        this.ops.push(op);
        this.redraw();
        if (this.tool === 'crop') this.startCrop();
    }

    undo() {
        this.commitText();
        if (!this.ops.length) return;
        this.ops.pop();
        this.redraw();
        if (this.tool === 'crop') this.startCrop();
    }

    redraw() {
        this.result = render(this.base, this.ops);
        this.canvas.width = this.result.width;
        this.canvas.height = this.result.height;
        this.canvas.getContext('2d').drawImage(this.result, 0, 0);
        this.overlay.querySelector('[data-editor-undo]').disabled = this.ops.length === 0;
    }

    setTool(tool) {
        this.commitText();
        this.tool = tool;

        for (const button of this.overlay.querySelectorAll('[data-editor-tool]')) {
            button.setAttribute('aria-pressed', String(button.dataset.editorTool === tool));
        }

        this.overlay.dataset.tool = tool;
        if (tool === 'crop') {
            this.startCrop();
        } else {
            this.crop = null;
            this.cropBox.hidden = true;
        }
        this.renderOptions();
    }

    setColor(color) {
        this.color = color;
        this.renderOptions();
        const input = this.wrap.querySelector('.image-editor-text-input');
        if (input) {
            input.style.color = color;
            input.focus();
        }
    }

    setSize(size) {
        this.size = size;
        this.renderOptions();
    }

    renderOptions() {
        if (this.tool === 'crop') {
            this.options.innerHTML = html`
                <span class="image-editor-hint">Drag the corners to crop</span>
                <button type="button" class="btn btn-secondary btn-sm" data-crop-reset>Reset</button>
                <button type="button" class="btn btn-primary btn-sm" data-crop-apply>Crop</button>
            `;
            return;
        }

        const colors = COLORS.map(
            (color) => html`<button type="button" class="image-editor-color${color === this.color ? ' is-active' : ''}" data-color="${color}" style="--swatch: ${color}" aria-label="Colour ${color}" aria-pressed="${String(color === this.color)}"></button>`,
        ).join('');
        const sizes = this.tool === 'draw'
            ? Object.keys(SIZES).map(
                (size) => html`<button type="button" class="image-editor-size${size === this.size ? ' is-active' : ''}" data-size="${size}" aria-label="Brush size ${size}" aria-pressed="${String(size === this.size)}"><i style="--dot: ${size === 'S' ? 0.35 : size === 'M' ? 0.6 : 0.95}rem"></i></button>`,
            ).join('')
            : html`<span class="image-editor-hint">Tap the photo to add text</span>`;

        this.options.innerHTML = `<div class="image-editor-colors">${colors}</div><div class="image-editor-sizes">${sizes}</div>`;
    }

    /* ---- pointer input ---- */

    /** Pointer position in image pixels. */
    toImage(event) {
        const rect = this.canvas.getBoundingClientRect();
        return [
            ((event.clientX - rect.left) / rect.width) * this.canvas.width,
            ((event.clientY - rect.top) / rect.height) * this.canvas.height,
        ];
    }

    longestEdge() {
        return Math.max(this.canvas.width, this.canvas.height);
    }

    pointerDown(event) {
        if (this.tool === 'draw') {
            event.preventDefault();
            this.canvas.setPointerCapture?.(event.pointerId);
            this.stroke = { type: 'stroke', points: [this.toImage(event)], color: this.color, size: SIZES[this.size] * this.longestEdge() };
            drawStroke(this.canvas.getContext('2d'), this.stroke);
        } else if (this.tool === 'text') {
            event.preventDefault();
            this.commitText();
            this.startText(event);
        }
    }

    pointerMove(event) {
        if (this.stroke) {
            const point = this.toImage(event);
            const previous = this.stroke.points[this.stroke.points.length - 1];
            if (Math.hypot(point[0] - previous[0], point[1] - previous[1]) < 1.5) return;
            this.stroke.points.push(point);
            drawStroke(this.canvas.getContext('2d'), { ...this.stroke, points: [previous, point] });
        } else if (this.drag) {
            const scale = this.canvas.width / this.canvas.getBoundingClientRect().width;
            const dx = (event.clientX - this.drag.startX) * scale;
            const dy = (event.clientY - this.drag.startY) * scale;
            this.crop = adjustRect(this.drag.rect, this.drag.handle, dx, dy, this.canvas.width, this.canvas.height);
            this.positionCrop();
        }
    }

    pointerUp() {
        if (this.stroke) {
            const stroke = this.stroke;
            this.stroke = null;
            this.push(stroke);
        }
        this.drag = null;
    }

    /* ---- crop ---- */

    startCrop() {
        this.crop = { x: 0, y: 0, w: this.canvas.width, h: this.canvas.height };
        this.cropBox.hidden = false;
        requestAnimationFrame(() => this.positionCrop());
    }

    cropPointerDown(event) {
        event.preventDefault();
        this.cropBox.setPointerCapture?.(event.pointerId);
        this.drag = {
            handle: event.target.dataset.handle ?? 'move',
            startX: event.clientX,
            startY: event.clientY,
            rect: { ...this.crop },
        };
    }

    positionCrop() {
        if (!this.crop || this.cropBox.hidden) return;
        const canvasRect = this.canvas.getBoundingClientRect();
        const wrapRect = this.wrap.getBoundingClientRect();
        const scale = canvasRect.width / this.canvas.width;

        Object.assign(this.cropBox.style, {
            left: `${canvasRect.left - wrapRect.left + this.crop.x * scale}px`,
            top: `${canvasRect.top - wrapRect.top + this.crop.y * scale}px`,
            width: `${this.crop.w * scale}px`,
            height: `${this.crop.h * scale}px`,
        });
    }

    applyCrop() {
        const rect = clampRect(
            { x: Math.round(this.crop.x), y: Math.round(this.crop.y), w: Math.round(this.crop.w), h: Math.round(this.crop.h) },
            this.canvas.width,
            this.canvas.height,
        );
        const unchanged = rect.x === 0 && rect.y === 0 && rect.w === this.canvas.width && rect.h === this.canvas.height;

        this.tool = 'draw';
        if (!unchanged) this.ops.push({ type: 'crop', rect });
        this.redraw();
        this.setTool('draw');
    }

    /* ---- text ---- */

    startText(event) {
        const [x, y] = this.toImage(event);
        const canvasRect = this.canvas.getBoundingClientRect();
        const wrapRect = this.wrap.getBoundingClientRect();
        const scale = canvasRect.width / this.canvas.width;
        const size = TEXT_SIZE * this.longestEdge();

        const input = document.createElement('input');
        input.type = 'text';
        input.maxLength = 80;
        input.className = 'image-editor-text-input';
        input.setAttribute('aria-label', 'Text on photo');
        Object.assign(input.style, {
            left: `${canvasRect.left - wrapRect.left + x * scale}px`,
            top: `${canvasRect.top - wrapRect.top + y * scale}px`,
            fontSize: `${Math.max(14, size * scale)}px`,
            color: this.color,
        });

        this.pendingText = { input, x, y, size };
        input.addEventListener('keydown', (keyEvent) => {
            if (keyEvent.key === 'Enter') {
                keyEvent.preventDefault();
                this.commitText();
            } else if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                this.pendingText = null;
                input.remove();
            }
        });
        input.addEventListener('blur', () => setTimeout(() => this.commitText(), 0));

        this.wrap.appendChild(input);
        input.focus();
    }

    commitText() {
        const pending = this.pendingText;
        if (!pending) return;
        this.pendingText = null;

        const text = pending.input.value.trim();
        pending.input.remove();
        if (text) this.push({ type: 'text', x: pending.x, y: pending.y, text, color: this.color, size: pending.size });
    }

    /* ---- finish ---- */

    async finish() {
        this.commitText();
        if (!this.ops.length) {
            this.close(this.file);
            return;
        }

        const toBlob = (type) => new Promise((resolve) => this.result.toBlob(resolve, type, 0.92));
        let format = outputFormat(this.file);
        let blob = await toBlob(format.type);

        if (blob && format.type === 'image/png' && blob.size > this.maxBytes) {
            format = outputFormat(this.file, true);
            blob = await toBlob(format.type);
        }

        if (!blob) {
            toast.error('The edited photo could not be saved.');
            return;
        }

        this.close(new File([blob], format.name, { type: format.type, lastModified: Date.now() }));
    }

    close(result) {
        this.commitText();
        document.removeEventListener('keydown', this.onKey);
        window.removeEventListener('resize', this.onResize);
        this.overlay.remove();
        document.body.style.overflow = '';
        this.previouslyFocused?.focus?.();
        this.resolve(result);
    }
}
