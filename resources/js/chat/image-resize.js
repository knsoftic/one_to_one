/**
 * M16 — Photos are resized in the browser before upload, like WhatsApp:
 * standard quality 1600 px, HD 3072 px (the sizes the server keeps). Saves
 * mobile data, lets large phone photos through the upload limit, and drops
 * EXIF/GPS metadata already on the device.
 */
export const STANDARD_EDGE = 1600;
export const HD_EDGE = 3072;
/** Photos bigger than this are refused before any work (they would be resized anyway). */
export const MAX_SOURCE_BYTES = 40 * 1024 * 1024;

/** Size after fitting the longest edge into maxEdge (never enlarged). */
export function fitSize(width, height, maxEdge) {
    const scale = Math.min(1, maxEdge / Math.max(width, height));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)), scale };
}

/** JPEG name for a converted photo. */
export function jpegName(name) {
    return `${String(name || 'photo').replace(/\.[^.]+$/, '') || 'photo'}.jpg`;
}

/**
 * @returns {Promise<File>} the original when it is already small enough, otherwise a resized copy
 */
export async function prepareImage(file, { hd = false, maxBytes = Infinity } = {}) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return file; // the server validates and processes it
    }

    const target = fitSize(bitmap.width, bitmap.height, hd ? HD_EDGE : STANDARD_EDGE);
    if (target.scale === 1 && file.size <= maxBytes) {
        bitmap.close?.();
        return file;
    }

    const canvas = document.createElement('canvas');
    canvas.width = target.width;
    canvas.height = target.height;
    const ctx = canvas.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bitmap, 0, 0, target.width, target.height);
    bitmap.close?.();

    const encode = (type, quality) => new Promise((resolve) => canvas.toBlob(resolve, type, quality));

    // PNG keeps transparency when it stays within the limit; otherwise JPEG.
    if (file.type === 'image/png') {
        const png = await encode('image/png');
        if (png && png.size <= maxBytes) return new File([png], file.name, { type: 'image/png', lastModified: Date.now() });
    }

    const jpeg = await encode('image/jpeg', hd ? 0.9 : 0.82);
    return jpeg ? new File([jpeg], jpegName(file.name), { type: 'image/jpeg', lastModified: Date.now() }) : file;
}
