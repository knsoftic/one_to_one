import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { openLightbox } from './lightbox';
import { openVideoPlayer } from './video';

/**
 * M22 — View once: the receiver taps to open the photo, video or voice message
 * a single time; it loads through a short-lived link and cannot be reopened.
 */

export const VIEW_ONCE_LABELS = { image: 'Photo', video: 'Video', voice: 'Voice message' };

/** What a view once bubble should say for this person. */
export function viewOnceState(message) {
    const label = VIEW_ONCE_LABELS[message.type] ?? 'Media';
    const opened = Boolean(message.view_once?.opened_at);

    if (message.is_mine) return { label, text: opened ? 'Opened' : label, canOpen: false, opened };
    if (opened || message.view_once?.available === false) return { label, text: 'Opened', canOpen: false, opened: true };
    return { label, text: label, canOpen: typeof message.id === 'number', opened: false };
}

export class ViewOnce {
    constructor(chat) {
        this.chat = chat;
        this.opening = new Set();

        chat.el.messageList.addEventListener('click', (event) => {
            const button = event.target.closest('[data-view-once]');
            if (button) this.open(Number(button.dataset.viewOnce));
        });
    }

    async open(messageId) {
        const message = this.chat.active?.byId.get(String(messageId));
        if (!message || this.opening.has(messageId)) return;

        this.opening.add(messageId);
        try {
            const result = await this.chat.api.openViewOnce(messageId);
            this.chat.updateMessage(this.chat.normalizeMessage(result.message));
            this.show(message.type, result.url);
        } catch (error) {
            toast.error(errorMessage(error, 'This view once message cannot be opened.'));
            if (error?.response?.status === 410) {
                this.chat.updateMessage({ ...message, view_once: { ...(message.view_once ?? {}), available: false, opened_at: message.view_once?.opened_at ?? new Date().toISOString() } });
            }
        } finally {
            this.opening.delete(messageId);
        }
    }

    show(type, url) {
        if (type === 'image') {
            openLightbox({ src: url, name: 'View once photo', protect: true });
        } else if (type === 'video') {
            openVideoPlayer({ src: url, name: 'View once video', protect: true });
        } else {
            this.playVoice(url);
        }
    }

    playVoice(url) {
        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal view-once-voice';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'View once voice message');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-view-once-close></div>
            <div class="modal-panel">
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('mic'))}</div>
                <h2 class="modal-title">View once voice message</h2>
                <p class="modal-text">It can be played only now.</p>
                <audio src="${url}" controls autoplay controlslist="nodownload noplaybackrate" class="view-once-audio"></audio>
                <div class="modal-actions"><button type="button" class="btn btn-primary" data-view-once-close>Done</button></div>
            </div>
        `;

        const audio = overlay.querySelector('audio');
        const close = () => {
            audio.pause();
            audio.removeAttribute('src');
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-view-once-close]')) close();
        });
        overlay.addEventListener('contextmenu', (event) => event.preventDefault());
        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('.btn').focus();
    }
}
