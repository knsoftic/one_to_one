/**
 * D5 — media auto-download: on Wi-Fi or on mobile data, photos, GIFs and stickers and
 * video previews load by themselves only when Settings → Storage and data allows it.
 * Everything else waits behind a "download" button with the file size.
 */

export const DEFAULT_AUTO_DOWNLOAD = { wifi: ['photos', 'gifs', 'videos'], mobile: ['photos', 'gifs'] };

export const DOWNLOAD_KINDS = [
    { key: 'photos', label: 'Photos' },
    { key: 'gifs', label: 'GIFs and stickers' },
    { key: 'videos', label: 'Video previews' },
];

/** "mobile" on mobile data or with Data Saver on; otherwise "wifi" (also when the browser can't tell). */
export function networkType(nav = globalThis.navigator) {
    const connection = nav?.connection || nav?.mozConnection || nav?.webkitConnection;
    if (!connection) return 'wifi';
    if (connection.saveData) return 'mobile';
    if (connection.type === 'cellular') return 'mobile';
    return 'wifi';
}

/** Which auto-download choice covers a message (null = always shown). */
export function downloadKind(message) {
    if (!message?.attachment) return null;
    if (message.type === 'sticker') return 'gifs';
    if (message.type === 'image') return message.attachment.animated || message.attachment.mime === 'image/gif' ? 'gifs' : 'photos';
    if (message.type === 'video') return 'videos';
    return null;
}

export class AutoDownload {
    /**
     * @param {() => object} prefs returns {wifi: string[], mobile: string[]}
     */
    constructor(prefs, nav = globalThis.navigator) {
        this.prefs = prefs;
        this.nav = nav;
        this.loaded = new Set();
    }

    allows(message) {
        const kind = downloadKind(message);
        // My own media, uploads in progress and files already fetched are always shown.
        if (!kind || message.is_mine || message.uploading || message.attachment?.local_url || message.media_loaded) return true;
        if (this.loaded.has(String(message.id))) return true;

        const choices = { ...DEFAULT_AUTO_DOWNLOAD, ...(this.prefs() ?? {}) };
        return (choices[networkType(this.nav)] ?? []).includes(kind);
    }

    markLoaded(id) {
        this.loaded.add(String(id));
    }
}
