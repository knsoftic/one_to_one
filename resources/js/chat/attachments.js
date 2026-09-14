import { formatBytes } from '../lib/dom';
import { toast } from '../lib/toast';
import { newAlbumId } from './album';
import { AttachMenu } from './attach-menu';
import { MAX_SOURCE_BYTES, prepareImage } from './image-resize';
import * as T from './templates';
import { videoDetails } from './video';

/** WhatsApp lets you pick up to 30 items at once. */
export const MAX_FILES = 30;

/**
 * Photos, videos and documents in the composer: picker (several at once),
 * drag & drop, clipboard paste, client-side validation and a preview tray.
 * The message box holds the caption of the selected item.
 */
export class AttachmentComposer {
    constructor(chat) {
        this.chat = chat;
        /** @type {{file: File, type: string, caption: string, previewUrl: string|null, video: object|null}[]} */
        this.items = [];
        this.activeIndex = 0;
        /** Send photos in HD (M16); applies to every photo in the tray, like WhatsApp. */
        this.hd = false;
        /** Send photos and videos as view once (M22). */
        this.viewOnce = false;
        this.dragDepth = 0;

        const limits = chat.config.limits;
        this.imageExtensions = limits.image.extensions;
        this.documentExtensions = limits.document.extensions;
        this.videoExtensions = limits.video?.extensions ?? [];
        this.imageMaxBytes = limits.image.max_kb * 1024;
        this.documentMaxBytes = limits.document.max_kb * 1024;
        this.videoMaxBytes = (limits.video?.max_kb ?? 0) * 1024;

        this.button = document.querySelector('[data-attach-button]');
        this.input = document.querySelector('[data-attach-input]');
        this.preview = chat.el.composerExtras.querySelector('[data-attachment-preview]');
        this.overlay = document.querySelector('[data-drop-overlay]');

        this.bind();
    }

    /** The first picked file (kept for callers that only check whether something is attached). */
    get file() {
        return this.items[0]?.file ?? null;
    }

    bind() {
        // The paperclip opens the attach menu; features add entries (location, contact, poll…).
        this.menu = new AttachMenu(this.chat, this.button);
        this.menu.add({ id: 'files', icon: 'image-plus', label: 'Photos, videos & files', run: () => this.input.click() });
        this.button.addEventListener('click', () => this.menu.toggle());

        // In-app camera (M15): the photo or video lands in the tray like a picked file.
        const cameraButton = document.querySelector('[data-camera-button]');
        if (cameraButton && navigator.mediaDevices?.getUserMedia) {
            cameraButton.hidden = false;
            cameraButton.addEventListener('click', async () => {
                if (!this.chat.active || this.chat.el.composer.classList.contains('is-disabled')) return;
                const { openCamera } = await import('./camera');
                openCamera({ maxVideoBytes: this.videoMaxBytes, onCapture: (file) => this.add([file]) });
            });
        }

        this.input.addEventListener('change', () => {
            const files = [...(this.input.files ?? [])];
            this.input.value = '';
            if (files.length) this.add(files);
        });

        this.preview.addEventListener('click', (event) => {
            const item = event.target.closest('[data-attachment-item]');
            if (event.target.closest('[data-remove-attachment]')) this.remove(this.activeIndex);
            else if (event.target.closest('[data-edit-attachment]')) this.edit(this.activeIndex);
            else if (event.target.closest('[data-hd-toggle]')) this.toggleHd();
            else if (event.target.closest('[data-view-once-toggle]')) this.toggleViewOnce();
            else if (event.target.closest('[data-attachment-add]')) this.input.click();
            else if (item) this.setActive(Number(item.dataset.attachmentItem));
        });

        // Paste images straight from the clipboard.
        this.chat.el.composerInput.addEventListener('paste', (event) => {
            const files = [...(event.clipboardData?.files ?? [])];
            if (files.length) {
                event.preventDefault();
                this.add(files);
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
            const files = [...(event.dataTransfer.files ?? [])];
            if (files.length) this.add(files);
        });

        document.addEventListener('chat:before-submit', (event) => {
            if (!this.items.length || event.defaultPrevented) return;
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
        if (this.videoExtensions.includes(extension)) return 'video';
        if (this.documentExtensions.includes(extension)) return 'document';
        return null;
    }

    /** Validate and add picked files to the tray. */
    async add(files) {
        if (!this.chat.active || this.chat.el.composer.classList.contains('is-disabled')) return;

        const accepted = [];
        for (const file of files) {
            const type = this.classify(file);
            if (!type) {
                toast.error(`${file.name}: this file type cannot be sent. Photos, videos, PDF, Word, Excel, PowerPoint, TXT, CSV, ZIP, RAR, 7Z and MP3 files are allowed.`);
                continue;
            }

            // Photos are resized before upload (M16), so only absurdly large ones are refused here.
            const max = { image: MAX_SOURCE_BYTES, video: this.videoMaxBytes }[type] ?? this.documentMaxBytes;
            if (file.size > max) {
                const label = { image: 'Images', video: 'Videos' }[type] ?? 'Documents';
                toast.error(`${file.name}: ${label.toLowerCase()} may not be larger than ${formatBytes(max)}.`);
                continue;
            }

            accepted.push({ file, type, caption: '', previewUrl: type === 'image' ? URL.createObjectURL(file) : null, video: null });
        }

        const room = MAX_FILES - this.items.length;
        if (accepted.length > room) {
            toast.error(`You can send up to ${MAX_FILES} files at once.`);
            accepted.splice(room).forEach((item) => item.previewUrl && URL.revokeObjectURL(item.previewUrl));
        }
        if (!accepted.length) return;

        // Text typed before picking becomes the caption of the first file.
        if (this.items.length) {
            this.saveCaption();
            this.items.push(...accepted);
            this.activeIndex = this.items.length - accepted.length;
            this.loadCaption();
        } else {
            this.items.push(...accepted);
            this.activeIndex = 0;
        }

        this.render();
        this.chat.updateSendState();
        this.chat.el.composerInput.focus();

        for (const item of accepted.filter((entry) => entry.type === 'video')) {
            const details = await videoDetails(item.file);
            if (!this.items.includes(item)) continue;
            item.video = details;
            item.previewUrl = details.thumbnail ? URL.createObjectURL(details.thumbnail) : null;
            this.render();
        }
    }

    /** Kept for single-file callers. */
    select(file) {
        return this.add([file]);
    }

    setActive(index) {
        if (index === this.activeIndex || !this.items[index]) return;
        this.saveCaption();
        this.activeIndex = index;
        this.loadCaption();
        this.render();
        this.chat.el.composerInput.focus();
    }

    /** Crop, rotate, draw on or write on a photo before sending (M14). */
    async edit(index) {
        const item = this.items[index];
        if (item?.type !== 'image' || /\.gif$/i.test(item.file.name)) return;

        const { openImageEditor } = await import('./image-editor');
        const edited = await openImageEditor(item.file, { maxBytes: this.imageMaxBytes });
        if (!edited || edited === item.file || !this.items.includes(item)) return;


        if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
        item.file = edited;
        item.previewUrl = URL.createObjectURL(edited);
        this.render();
        this.chat.el.composerInput.focus();
    }

    toggleViewOnce() {
        this.viewOnce = !this.viewOnce;
        this.render();
    }

    toggleHd() {
        this.hd = !this.hd;
        this.render();
    }

    remove(index) {
        const [item] = this.items.splice(index, 1);
        if (!item) return;
        if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);

        if (!this.items.length) {
            // The caption stays in the box as a normal message.
            this.clear();
            return;
        }

        this.activeIndex = Math.min(index, this.items.length - 1);
        this.loadCaption();
        this.render();
    }

    saveCaption() {
        const item = this.items[this.activeIndex];
        if (item) item.caption = this.chat.el.composerInput.value;
    }

    loadCaption() {
        this.chat.el.composerInput.value = this.items[this.activeIndex]?.caption ?? '';
        this.chat.autosize();
        this.chat.updateSendState();
    }

    render() {
        const items = this.items;

        if (items.length === 1) {
            const [item] = items;
            this.preview.innerHTML = T.attachmentPreview({
                type: item.type,
                name: item.file.name,
                size: item.file.size,
                url: item.previewUrl,
                duration: item.video?.duration ?? null,
                loading: item.type === 'video' && !item.video,
                hd: this.hd,
                viewOnce: this.viewOnceChoice(),
            });
            return;
        }

        this.preview.innerHTML = items.length
            ? T.attachmentTray({
                items: items.map((item, index) => ({
                    type: item.type,
                    name: item.file.name,
                    size: item.file.size,
                    url: item.previewUrl,
                    loading: item.type === 'video' && !item.video,
                    hasCaption: index !== this.activeIndex && Boolean(item.caption.trim()),
                })),
                activeIndex: this.activeIndex,
                canAdd: items.length < MAX_FILES,
                hd: this.hd,
                viewOnce: this.viewOnceChoice(),
            })
            : '';
    }

    /** View once is not offered in "Message yourself" (C7): null hides the switch. */
    viewOnceChoice() {
        return this.chat.activeConversation?.()?.is_self ? null : this.viewOnce;
    }

    clear() {
        for (const item of this.items) {
            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
        }
        this.items = [];
        this.activeIndex = 0;
        this.hd = false;
        this.viewOnce = false;
        this.preview.innerHTML = '';
        this.chat.updateSendState();
    }

    async send() {
        this.saveCaption();

        const items = this.items.map((item) => ({ ...item, caption: item.caption.trim() }));
        const conversationId = this.chat.active.id;
        const limit = this.chat.config.limits.messageLength;

        if (items.some((item) => item.caption.length > limit)) {
            toast.error(`Captions can be at most ${limit} characters.`);
            return;
        }

        // Photos and videos picked together are shown as one album.
        const media = items.filter((item) => item.type === 'image' || item.type === 'video');
        // View once media is never grouped into an album.
        const albumId = media.length > 1 && !this.viewOnce ? newAlbumId() : null;
        const hd = this.hd;
        const viewOnce = this.viewOnceChoice() === true;

        // The poster blobs stay usable after their preview URLs are revoked.
        this.clear();
        this.chat.el.composerInput.value = '';
        this.chat.drafts?.clear(conversationId);
        this.chat.autosize();
        this.chat.updateSendState();
        this.chat.stopTyping();

        for (const item of items) {
            // A video sent before its poster was ready: read it now.
            const video = item.type === 'video' ? item.video ?? (await videoDetails(item.file)) : null;
            // GIFs are sent as they are, so they stay animated.
            const gif = item.type === 'image' && /\.gif$/i.test(item.file.name);
            const file = item.type === 'image' && !gif ? await prepareImage(item.file, { hd, maxBytes: this.imageMaxBytes }) : item.file;

            if (item.type === 'image' && file.size > this.imageMaxBytes) {
                toast.error(`${item.file.name}: the photo is too large to send (max ${formatBytes(this.imageMaxBytes)}).`);
                continue;
            }

            this.chat.sendFile(conversationId, {
                file,
                type: item.type,
                caption: item.caption,
                fileName: file.name,
                quality: item.type === 'image' && !gif && hd ? 'hd' : null,
                viewOnce: viewOnce && ((item.type === 'image' && !gif) || item.type === 'video'),
                albumId: item.type === 'image' || item.type === 'video' ? albumId : null,
                ...(video ? { duration: video.duration, thumbnail: video.thumbnail, width: video.width, height: video.height } : {}),
            });
        }
    }
}
