import { formatBytes } from '../lib/dom';
import { toast } from '../lib/toast';
import { ForwardDialog } from './forward';

const CHUNK = 512 * 1024;

/** "2 photos, 1 video · Check this out" */
export function describeShare({ text = '', files = [] }) {
    const counts = {};
    for (const file of files) {
        const kind = file.type?.startsWith('image/') ? 'photo' : file.type?.startsWith('video/') ? 'video' : 'file';
        counts[kind] = (counts[kind] ?? 0) + 1;
    }
    const parts = Object.entries(counts).map(([kind, n]) => `${n} ${kind}${n === 1 ? '' : 's'}`);
    const snippet = text.replace(/\s+/g, ' ').trim();
    if (snippet) parts.push(snippet.length > 60 ? `${snippet.slice(0, 60)}…` : snippet);
    return parts.join(' · ') || 'Shared content';
}

/** "Send to…": the forward dialog, sending what another app shared. */
export class ShareDialog extends ForwardDialog {
    labels() {
        return { title: 'Send to…', preview: describeShare(this.message), icon: 'share', button: 'Send', busy: 'Sending…' };
    }

    async deliver(conversationIds) {
        const { text = '', files = [] } = this.message;
        const chat = this.chat;
        this.close();

        // One chat: open it with the files ready to caption and the text in the box, like WhatsApp.
        if (conversationIds.length === 1) {
            await chat.openConversation(conversationIds[0]);
            for (let i = 0; i < 50 && !chat.active?.loaded; i++) await new Promise((resolve) => setTimeout(resolve, 100));
            if (files.length) await chat.attachments.add(files);
            if (text) {
                chat.el.composerInput.value = text;
                chat.autosize?.();
                chat.updateSendState?.();
            }
            this.onDone?.();
            return;
        }

        // Several chats: send right away.
        let sent = 0;
        for (const id of conversationIds) {
            if (text) {
                chat.sendText(id, text);
            }
            for (const file of files) {
                const type = chat.attachments.classify(file);
                if (!type) continue;
                chat.sendFile(id, { file, type });
            }
            sent++;
        }
        this.onDone?.();
        toast.success(`Sent to ${sent} chats.`, { timeout: 2500 });
    }
}

/**
 * X2 — content shared into the app: from other Android apps (native plugin) or, for the
 * installed web app, through the share target (?share_text=…).
 */
export class ShareReceiver {
    constructor(chat) {
        this.chat = chat;
        this.dialog = new ShareDialog(chat);
        this.native = null;

        const params = new URLSearchParams(window.location.search);
        const shared = ['share_title', 'share_text', 'share_url'].map((key) => params.get(key)).filter(Boolean);
        if (shared.length) {
            // Keep the address clean so a reload doesn't share again.
            ['share_title', 'share_text', 'share_url'].forEach((key) => params.delete(key));
            const query = params.toString();
            history.replaceState(history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
            document.addEventListener('chat:ready', () => this.open({ text: [...new Set(shared)].join('\n') }), { once: true });
        }
    }

    open(content) {
        if (!content?.text && !content?.files?.length) return;
        this.dialog.onDone = () => this.native?.clearSharedContent().catch(() => {});
        this.dialog.open(content);
    }

    /** Android app: ask for waiting shares now and whenever a new one arrives. */
    listenNative(NativeApp) {
        this.native = NativeApp;
        NativeApp.addListener?.('shareReceived', () => this.checkNative());
        return this.checkNative();
    }

    async checkNative() {
        const NativeApp = this.native;
        const shared = await NativeApp.getSharedContent().catch(() => null);
        if (!shared?.id) return null;

        const skipped = (shared.files ?? []).filter((file) => file.skipped);
        const readable = (shared.files ?? []).filter((file) => !file.skipped);
        if (skipped.length) toast.error(`${skipped.length === 1 ? '1 file' : `${skipped.length} files`} could not be shared (larger than ${formatBytes(100 * 1024 * 1024)} or unreadable).`);

        let files = [];
        if (readable.length) {
            const preparing = readable.some((file) => file.size > 5 * 1024 * 1024) ? toast.info('Preparing…', { timeout: 0 }) : null;
            try {
                files = await Promise.all(readable.map((file) => readNativeFile(NativeApp, file)));
            } finally {
                preparing?.remove?.();
            }
        }

        const content = { text: shared.text ?? '', files };
        this.open(content);
        return content;
    }
}

/** Put a shared file back together from base64 pieces. */
export async function readNativeFile(NativeApp, file, chunk = CHUNK) {
    const parts = [];
    for (let offset = 0; offset < file.size; offset += chunk) {
        const { data } = await NativeApp.readSharedFile({ index: file.index, offset, length: chunk });
        const raw = atob(data);
        parts.push(Uint8Array.from(raw, (char) => char.charCodeAt(0)));
    }
    return new File(parts, file.name, { type: file.mime || 'application/octet-stream' });
}
