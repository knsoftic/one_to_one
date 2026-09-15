import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/**
 * Poster frame (JPEG), size and duration of a video file, read in the browser.
 * Videos the browser cannot decode still send, just without a poster.
 *
 * @returns {Promise<{thumbnail: Blob|null, width: number, height: number, duration: number|null}>}
 */
export function videoDetails(file, { maxWidth = 640, timeoutMs = 8000 } = {}) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video');
        let settled = false;

        const durationOf = () => (Number.isFinite(video.duration) && video.duration > 0 ? video.duration : null);
        const finish = (result) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            video.removeAttribute('src');
            video.load();
            URL.revokeObjectURL(url);
            resolve(result);
        };
        const empty = () => finish({ thumbnail: null, width: 0, height: 0, duration: durationOf() });
        const timer = setTimeout(empty, timeoutMs);

        video.muted = true;
        video.playsInline = true;
        video.preload = 'auto';

        video.addEventListener('error', empty);
        video.addEventListener('loadedmetadata', () => {
            const duration = durationOf();
            // A frame a little way in: the very first one is often black. Recordings
            // may not report a duration yet; a small seek still produces a frame.
            video.currentTime = duration ? Math.min(1, duration / 4) : 0.1;
        });
        video.addEventListener(
            'seeked',
            () => {
                const { videoWidth: width, videoHeight: height } = video;
                if (!width || !height) {
                    empty();
                    return;
                }

                const scale = Math.min(1, maxWidth / width);
                const canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(width * scale));
                canvas.height = Math.max(1, Math.round(height * scale));

                try {
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob((blob) => finish({ thumbnail: blob, width, height, duration: durationOf() }), 'image/jpeg', 0.82);
                } catch {
                    empty();
                }
            },
            { once: true },
        );

        video.src = url;
    });
}

/**
 * Full-screen video player (tap a video in the chat).
 */
export function openVideoPlayer({ src, name = '', download = '', poster = '', protect = false, onShowInChat = null }) {
    const previouslyFocused = document.activeElement;
    const overlay = document.createElement('div');
    overlay.className = 'lightbox is-loaded is-video';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', name || 'Video');

    overlay.innerHTML = html`
        <div class="lightbox-bar">
            <span class="lightbox-name">${name}</span>
            <span class="flex items-center gap-1">
                ${raw(onShowInChat ? html`<button type="button" class="btn-icon lightbox-btn" data-lightbox-jump aria-label="Show in chat" title="Show in chat">${raw(icon('message-square'))}</button>` : '')}
                ${raw(download ? html`<a class="btn-icon lightbox-btn" href="${download}" download="${name}" aria-label="Download">${raw(icon('download'))}</a>` : '')}
                <button type="button" class="btn-icon lightbox-btn" data-lightbox-close aria-label="Close">${raw(icon('x'))}</button>
            </span>
        </div>
        <div class="lightbox-stage" data-lightbox-close>
            <video class="lightbox-video" src="${src}" ${raw(poster ? html`poster="${poster}"` : '')} controls autoplay playsinline preload="auto" ${raw(protect ? 'controlslist="nodownload" disablepictureinpicture' : '')}></video>
        </div>
    `;

    const video = overlay.querySelector('video');
    if (protect) overlay.addEventListener('contextmenu', (event) => event.preventDefault());

    const close = () => {
        document.removeEventListener('keydown', onKey);
        video.pause();
        video.removeAttribute('src');
        video.load();
        overlay.classList.add('is-closing');
        setTimeout(() => overlay.remove(), 180);
        document.body.style.overflow = '';
        previouslyFocused?.focus?.();
    };

    const onKey = (event) => {
        if (event.key === 'Escape') close();
    };

    overlay.addEventListener('click', (event) => {
        if (event.target.closest('[data-lightbox-jump]')) {
            close();
            onShowInChat?.();
            return;
        }
        if (event.target.closest('[data-lightbox-close]') && !event.target.closest('video')) close();
    });

    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    video.play?.()?.catch?.(() => {});
}
