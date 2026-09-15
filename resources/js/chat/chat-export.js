import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * D7 — Export chat (chat menu → Export chat): the messages I can see as a text file,
 * or a ZIP with the photos, videos, voice messages and documents too.
 */
export class ChatExport {
    constructor(chat) {
        this.chat = chat;
        if (!chat.api.has('conversationExport')) return;

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (!conversation || conversation.is_locked_out) return;
            items.push(html`<button type="button" class="dropdown-item" data-action="export-chat" role="menuitem">${raw(icon('file-down'))} Export chat</button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'export-chat') this.open();
        });
    }

    get mediaAvailable() {
        return this.chat.config?.export?.media !== false;
    }

    url(conversationId, withMedia) {
        const base = this.chat.api.url('conversationExport', conversationId);
        return withMedia ? `${base}?media=1` : base;
    }

    open() {
        const conversation = this.chat.activeConversation();
        if (!conversation) return;

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal export-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'export-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-export-cancel></div>
            <div class="modal-panel export-panel">
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('file-down'))}</div>
                <h2 class="modal-title" id="export-title">Export chat</h2>
                <p class="modal-text">A file with the messages you can see in this chat. Anyone you share it with can read it.</p>
                <div class="export-choices">
                    <button type="button" class="export-choice" data-export="text">
                        <span class="export-choice-icon">${raw(icon('file-text'))}</span>
                        <span><strong>Without media</strong><small>Text file (.txt), quick and small</small></span>
                    </button>
                    <button type="button" class="export-choice" data-export="media" ${raw(this.mediaAvailable ? '' : 'disabled')}>
                        <span class="export-choice-icon">${raw(icon('folder-archive'))}</span>
                        <span><strong>Include media</strong><small>${this.mediaAvailable ? 'ZIP with photos, videos, voice messages and documents' : 'Not available on this server'}</small></span>
                    </button>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-export-cancel>Cancel</button>
                </div>
            </div>
        `;

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-export-cancel]')) return close();
            const choice = event.target.closest('[data-export]');
            if (!choice || choice.disabled) return null;
            close();
            this.download(conversation.id, choice.dataset.export === 'media');
            return null;
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('[data-export]')?.focus();
    }

    download(conversationId, withMedia) {
        const link = document.createElement('a');
        link.href = this.url(conversationId, withMedia);
        link.setAttribute('download', '');
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();
        toast.success(withMedia ? 'Preparing your chat with media… the download starts in a moment.' : 'Your chat is downloading.', { timeout: 4000 });
    }
}
