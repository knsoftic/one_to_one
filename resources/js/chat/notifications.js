import axios from '../bootstrap';
import { debounce, html, raw } from '../lib/dom';
import { toast } from '../lib/toast';
import { formatListTime } from './format';
import * as T from './templates';

const BANNER_KEY = 'chat:notify-banner-dismissed';

/**
 * New-message notifications:
 *  - in-app toast (click to open the chat) when the tab is visible,
 *  - desktop notification when the tab is in the background,
 *  - optional sound,
 *  - notification centre (bell) backed by Laravel database notifications,
 *  - unread counters in the tab title and app badge.
 */
export class Notifier {
    constructor(chat) {
        this.chat = chat;
        this.presented = new Set();
        this.unread = 0;
        this.audioContext = null;

        this.el = {
            toggle: document.querySelector('[data-notifications-toggle]'),
            count: document.querySelector('[data-notifications-count]'),
            list: document.querySelector('[data-notifications-list]'),
            readAll: document.querySelector('[data-notifications-read-all]'),
            banner: document.querySelector('[data-notify-banner]'),
        };

        this.refreshCount = debounce(() => this.load(), 800);

        this.bind();
        this.load();
        this.renderBanner();
    }

    get prefs() {
        return this.chat.config.user ?? {};
    }

    bind() {
        // Every incoming message (WebSocket or polling) is a candidate notification.
        document.addEventListener('chat:incoming', (event) => {
            const { message, viewing } = event.detail;
            if (viewing) return;

            const sender = this.chat.users.get(Number(message.sender_id)) ?? {};
            this.present({
                messageId: message.id,
                conversationId: Number(message.conversation_id),
                title: `${sender.name ?? 'Someone'} sent you a message`,
                body: T.previewOf(message),
                sender,
            });

            if (this.chat.realtime?.polling) this.refreshCount();
        });

        // Laravel broadcast notification (database + broadcast channels).
        this.chat.onNotification = (notification) => {
            if (notification.type !== 'message.notification') return;
            this.setCount(this.unread + 1);
            this.present({
                messageId: notification.message_id,
                conversationId: Number(notification.conversation_id),
                title: notification.title,
                body: notification.body,
                sender: notification.sender ?? {},
            });
        };

        document.addEventListener('chat:seen', () => this.refreshCount());

        document.addEventListener('chat:unread', (event) => {
            const total = event.detail.total;
            if ('setAppBadge' in navigator) {
                (total ? navigator.setAppBadge(total) : navigator.clearAppBadge?.())?.catch?.(() => {});
            }
        });

        this.el.toggle?.addEventListener('click', () => this.load());

        this.el.list?.addEventListener('click', (event) => {
            const item = event.target.closest('[data-notification-conversation]');
            if (item) this.chat.openConversation(Number(item.dataset.notificationConversation));
        });

        this.el.readAll?.addEventListener('click', async (event) => {
            event.stopPropagation();
            try {
                await axios.post(this.chat.api.url('notificationsRead'));
                this.load();
            } catch {
                /* ignore */
            }
        });

        this.el.banner?.addEventListener('click', async (event) => {
            if (event.target.closest('[data-notify-dismiss]')) {
                localStorage.setItem(BANNER_KEY, '1');
                this.renderBanner();
            }
            if (event.target.closest('[data-notify-enable]')) {
                const result = await Notification.requestPermission();
                if (result === 'granted') toast.success('Desktop notifications enabled.');
                this.renderBanner();
            }
        });

        // Browsers only allow audio after a user gesture: unlock it on first interaction.
        const unlock = () => {
            this.audio()?.resume?.().catch(() => {});
            window.removeEventListener('pointerdown', unlock);
        };
        window.addEventListener('pointerdown', unlock);
    }

    renderBanner() {
        const banner = this.el.banner;
        if (!banner) return;
        const show = 'Notification' in window
            && Notification.permission === 'default'
            && this.prefs.notifications_enabled !== false
            && !localStorage.getItem(BANNER_KEY);
        banner.hidden = !show;
    }

    /* ------------------------------------------------------------------ */
    /* Presentation                                                        */
    /* ------------------------------------------------------------------ */

    present({ messageId, conversationId, title, body, sender }) {
        if (!messageId || this.presented.has(messageId)) return;
        this.presented.add(messageId);
        if (this.presented.size > 300) this.presented.delete(this.presented.values().next().value);

        if (this.prefs.notifications_enabled === false) return;

        const visible = document.visibilityState === 'visible';
        if (visible && this.chat.active?.id === conversationId) return;

        if (this.prefs.notification_sound !== false) this.chime();

        const open = () => this.chat.openConversation(conversationId);

        if (!visible && 'Notification' in window && Notification.permission === 'granted') {
            try {
                const notification = new Notification(title, {
                    body,
                    icon: sender.avatar_url || '/favicon.svg',
                    tag: `conversation-${conversationId}`,
                    renotify: true,
                });
                notification.onclick = () => {
                    window.focus();
                    open();
                    notification.close();
                };
                return;
            } catch {
                /* fall back to an in-app toast */
            }
        }

        toast(body, {
            title,
            type: 'info',
            timeout: 5000,
            avatar: sender?.id ? T.avatar(sender, 'sm') : '',
            onClick: open,
        });
    }

    audio() {
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context) return null;
        this.audioContext ??= new Context();
        return this.audioContext;
    }

    /** Short two-tone chime generated with Web Audio (no asset needed). */
    chime() {
        const ctx = this.audio();
        if (!ctx || ctx.state === 'suspended') return;

        const now = ctx.currentTime;
        [
            [880, 0],
            [1320, 0.11],
        ].forEach(([frequency, offset]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.12, now + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.22);
            osc.connect(gain).connect(ctx.destination);
            osc.start(now + offset);
            osc.stop(now + offset + 0.25);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Notification centre                                                 */
    /* ------------------------------------------------------------------ */

    async load() {
        if (!this.chat.api.has('notifications')) return;
        try {
            const { data } = await axios.get(this.chat.api.url('notifications'));
            this.setCount(data.unread_count);
            this.renderList(data.data);
        } catch {
            /* non-critical */
        }
    }

    setCount(count) {
        this.unread = Math.max(0, count);
        const badge = this.el.count;
        if (!badge) return;
        badge.hidden = this.unread === 0;
        badge.textContent = this.unread > 99 ? '99+' : String(this.unread);
    }

    renderList(items) {
        if (!this.el.list) return;

        if (!items.length) {
            this.el.list.innerHTML = T.emptyState({ iconName: 'bell-off', title: 'No notifications', text: 'New messages will show up here.' });
            return;
        }

        this.el.list.innerHTML = items
            .map((item) => {
                const data = item.data ?? {};
                return html`
                    <button type="button" class="dropdown-item notification-item${item.read ? '' : ' is-unread'}" data-notification-conversation="${data.conversation_id}">
                        ${raw(T.avatar(data.sender ?? {}, 'sm'))}
                        <span class="notification-body">
                            <span class="notification-title">${data.title ?? 'New message'}</span>
                            <span class="notification-text">${data.body ?? ''}</span>
                        </span>
                        <span class="notification-time">${formatListTime(item.created_at)}</span>
                        ${raw(item.read ? '' : '<span class="notification-dot" aria-label="Unread"></span>')}
                    </button>
                `;
            })
            .join('');
    }
}
