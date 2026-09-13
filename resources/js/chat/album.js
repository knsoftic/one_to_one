/**
 * M13 — Photos and videos sent together (same album_id) are shown as a
 * WhatsApp-style album: a two-column grid of the first four, "+N" on the
 * fourth until it is opened. Two or three items stay separate bubbles.
 */
export const ALBUM_MIN = 4;
export const ALBUM_VISIBLE = 4;

/** A random v4 UUID (also where crypto.randomUUID is unavailable, e.g. plain http). */
export function newAlbumId() {
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();

    const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** A photo or video without caption that belongs to an album. */
export function isAlbumItem(message) {
    return Boolean(
        message?.album_id
        && !message.is_deleted
        && ['image', 'video'].includes(message.type)
        && message.attachment
        && !message.body,
    );
}

/**
 * Runs of consecutive album items from the same sender, at least ALBUM_MIN long.
 *
 * @returns {{id: string, start: number, end: number}[]} inclusive indexes
 */
export function albumRuns(messages) {
    const runs = [];
    let start = 0;

    for (let i = 1; i <= messages.length; i++) {
        const previous = messages[i - 1];
        const current = messages[i];
        const continues = Boolean(current)
            && isAlbumItem(previous)
            && isAlbumItem(current)
            && current.album_id === previous.album_id
            && Number(current.sender_id) === Number(previous.sender_id);

        if (!continues) {
            if (i - start >= ALBUM_MIN && isAlbumItem(messages[start])) {
                runs.push({ id: messages[start].album_id, start, end: i - 1 });
            }
            start = i;
        }
    }

    return runs;
}

/**
 * Position of every album item: {position, size, hidden, more, last}.
 *
 * @param {object[]} messages
 * @param {Set<string>} expanded album ids opened with "+N"
 * @returns {Map<string, {album: string, position: number, size: number, hidden: boolean, more: number, last: boolean}>}
 */
export function albumLayout(messages, expanded = new Set()) {
    const layout = new Map();

    for (const run of albumRuns(messages)) {
        const size = run.end - run.start + 1;
        const open = expanded.has(run.id) || size <= ALBUM_VISIBLE;

        for (let i = run.start; i <= run.end; i++) {
            const position = i - run.start;
            const lastVisible = open ? size - 1 : ALBUM_VISIBLE - 1;

            layout.set(String(messages[i].id), {
                album: run.id,
                position,
                size,
                hidden: position > lastVisible,
                more: !open && position === lastVisible ? size - ALBUM_VISIBLE : 0,
                last: position === lastVisible,
            });
        }
    }

    return layout;
}
