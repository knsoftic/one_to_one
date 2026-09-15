import axios from '../bootstrap';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { applyWallpaper, openWallpaperPicker, wallpaperLabel } from '../ui/wallpaper';

/**
 * D2 — chat wallpaper: the chat's own one, or the default from Settings → Chats.
 */
export class ChatWallpaper {
    constructor(chat, appConfig = window.App?.config) {
        this.chat = chat;
        this.appConfig = appConfig;
        this.main = document.querySelector('.chat-main');

        this.paint(null);

        document.addEventListener('chat:header', (event) => this.paint(event.detail.conversation));
        document.addEventListener('chat:closed', () => this.paint(null));

        if (!chat.api.has('conversationWallpaper')) return;

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (!conversation || conversation.is_locked_out) return;
            const own = conversation.settings?.wallpaper;
            items.push(html`<button type="button" class="dropdown-item" data-action="wallpaper" role="menuitem">${raw(icon('wallpaper'))} Wallpaper<span class="dropdown-item-hint">${own ? wallpaperLabel(own) : 'Default'}</span></button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'wallpaper') this.open();
        });
    }

    get defaultWallpaper() {
        return this.appConfig?.user?.wallpaper ?? { key: 'default', url: null, dim: 0 };
    }

    /** The chat's own wallpaper wins; without one the default from Settings is used. */
    paint(conversation) {
        const fallback = this.defaultWallpaper;
        applyWallpaper(this.main, conversation?.settings?.wallpaper ?? fallback, fallback.dim ?? 0);
    }

    open() {
        const conversation = this.chat.activeConversation();
        if (!conversation) return null;
        const conversationId = conversation.id;

        return openWallpaperPicker({
            title: 'Wallpaper for this chat',
            current: conversation.settings?.wallpaper ?? { key: 'default' },
            defaultLabel: 'Default',
            onSave: async (form) => {
                try {
                    const { data } = await axios.post(this.chat.api.url('conversationWallpaper', conversationId), form);
                    const merged = this.chat.upsertConversation(data);
                    if (this.chat.active?.id === conversationId) this.paint(merged);
                    toast.success('Wallpaper saved.', { timeout: 2000 });
                } catch (error) {
                    toast.error(errorMessage(error, 'Could not save the wallpaper.'));
                    throw error;
                }
            },
        });
    }
}
