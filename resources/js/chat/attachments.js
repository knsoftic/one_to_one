import { formatBytes } from '../lib/dom';
import { toast } from '../lib/toast';
import * as T from './templates';

/**
 * Image / document attachments in the composer: picker, drag & drop,
 * clipboard paste, client-side validation and preview with caption.
 */
export class AttachmentComposer {
    constructor(chat) {
        this.chat = chat;
        this.file = null;
        this.type = null;
        this.previewUrl = null;
        this.dragDepth = 0;

        const limits = chat.config.limits;
        this.imageExtensions = limits.image.extensions;
        this.documentExtensions = limits.document.extensions;
        this.imageMaxBytes = limits.image.max_kb * 1024;
        this.documentMaxBytes = limits.document.max_kb * 1024;

        this.button = document.querySelector('[data-attach-button]');
        this.input = document.querySelector('[data-attach-input]');
        this.preview = chat.el.composerExtras.querySelector('[data-attachment-preview]');
        this.overlay = document.querySelector('[data-drop-overlay]');

        this.bind();
    }

    bind() {
        this.button.addEventListener('click', () => this.input.click());

        this.input.addEventListener('change', () => {
            const file = this.input.files?.[0];
            this.input.value = '';
            if (file) this.select(file);
        });

        this.preview.addEventListener('click', (event) => {
            if (event.target.closest('[data-remove-attachment]')) this.clear();
        });

        // Paste an image straight from the clipboard.
        this.chat.el.composerInput.addEventListener('paste', (event) => {
            const file = [...(event.clipboardData?.files ?? [])][0];
            if (file) {
                event.preventDefault();
                this.select(file);
            }
        });

        // Drag & drop onto the conversation.
        const panel = this.chat.el.panel;
        panel.addEventListener('dragenter', (event) => {
            if (!this.acceptsDrag(event)) return;
            event.preventDefault();
            this.dragDepth++;
            this.overlay.hidden = false;
        });
        panel.addEventListener('dragover', (event) => {
            if (this.acceptsDrag(event)) event.preventDefault();
        });
        panel.addEventListener('dragleave', () => {
            this.dragDepth = Math.max(0, this.dragDepth - 1);
            if (!this.dragDepth) this.overlay.hidden = true;
        });
        panel.addEventListener('drop', (event) => {
            if (!this.acceptsDrag(event)) return;
            event.preventDefault();
            this.dragDepth = 0;
            this.overlay.hidden = true;
            const file = event.dataTransfer.files?.[0];
            if (file) this.select(file);
        });

        document.addEventListener('chat:before-submit', (event) => {
            if (!this.file || event.defaultPrevented) return;
            event.preventDefault();
            this.send();
        });

        document.addEventListener('chat:opened', () => this.clear());
        document.addEventListener('chat:closed', () => this.clear());
    }

    acceptsDrag(event) {
        return this.chat.active && !this.chat.el.composer.classList.contains('is-disabled')
            && [...(event.dataTransfer?.types ?? [])].includes('Files');
    }

    classify(file) {
        const extension = file.name.split('.').pop().toLowerCase();
        if (this.imageExtensions.includes(extension)) return 'image';
        if (this.documentExtensions.includes(extension)) return 'document';
        return null;
    }

    select(file) {
        if (!this.chat.active || this.chat.el.composer.classList.contains('is-disabled')) return;

        const type = this.classify(file);
        if (!type) {
            toast.error('Only JPG, JPEG, PNG, PDF, DOC and DOCX files can be sent.');
            return;
        }

        const max = type === 'image' ? this.imageMaxBytes : this.documentMaxBytes;
        if (file.size > max) {
            toast.error(`${type === 'image' ? 'Images' : 'Documents'} may not be larger than ${formatBytes(max)}.`);
            return;
        }

        this.clear();
        this.file = file;
        this.type = type;
        this.previewUrl = type === 'image' ? URL.createObjectURL(file) : null;
        this.preview.innerHTML = T.attachmentPreview({ type, name: file.name, size: file.size, url: this.previewUrl });

        this.chat.updateSendState();
        this.chat.el.composerInput.focus();
    }

    clear() {
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        this.file = null;
        this.type = null;
        this.previewUrl = null;
        this.preview.innerHTML = '';
        this.chat.updateSendState();
    }

    send() {
        const { file, type } = this;
        const input = this.chat.el.composerInput;
        const caption = input.value.trim();

        if (caption.length > this.chat.config.limits.messageLength) {
            toast.error(`Captions can be at most ${this.chat.config.limits.messageLength} characters.`);
            return;
        }

        this.clear();
        input.value = '';
        this.chat.autosize();
        this.chat.updateSendState();
        this.chat.stopTyping();

        this.chat.sendFile(this.chat.active.id, { file, type, caption, fileName: file.name });
    }
}
