import { errorMessage, formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';
import { dayKey, formatDayLabel, formatTime } from './format';
import * as T from './templates';

/**
 * K1 — Calls tab: every call (missed ones in red), grouped like WhatsApp, with call back.
 */

/**
 * Consecutive calls with the same person, direction and outcome on the same day become one row ("(3)").
 *
 * @param {object[]} calls newest first
 */
export function groupCalls(calls) {
    const groups = [];
    for (const call of calls) {
        const last = groups.at(-1);
        const head = last?.calls[0];
        if (head
            && Number(head.peer?.id) === Number(call.peer?.id)
            && head.direction === call.direction
            && head.missed === call.missed
            && dayKey(head.created_at) === dayKey(call.created_at)) {
            last.calls.push(call);
        } else {
            groups.push({ calls: [call] });
        }
    }
    return groups;
}

/** Small line under the name: "Today, 10:32 AM · 4:05". */
export function callLogDetail(call) {
    const when = `${formatDayLabel(call.created_at)}, ${formatTime(call.created_at)}`;
    if (call.duration) return `${when} · ${formatDuration(call.duration)}`;
    if (call.direction === 'outgoing' && ['missed', 'cancelled', 'busy', 'declined'].includes(call.end_reason)) return `${when} · Not answered`;
    return when;
}

export class CallLog {
    constructor(chat) {
        this.chat = chat;
        this.items = [];
        this.hasMore = false;
        this.loading = false;
        this.unseen = Number(chat.config.calls?.unseenMissed ?? 0);

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="calls"]'),
            scroll: q('[data-calls-scroll]'),
            list: q('[data-calls-list]'),
            more: q('[data-calls-more]'),
        };

        if (!this.el.view || !chat.api.has('callLog')) {
            document.querySelectorAll('[data-action="open-calls"], [data-mobile-tab="calls"]').forEach((el) => el.remove());
            return;
        }
        this.bind();
        this.renderBadge();
    }

    get isOpen() {
        return this.el.sidebar?.dataset.mode === 'calls';
    }

    bind() {
        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'open-calls') this.open();
            if (event.detail.action === 'close-calls') this.close();
            if (event.detail.action === 'clear-calls') this.clearAll();
            if (event.detail.action === 'new-group-call') this.chat.calls?.group?.startNew();
        });

        this.el.list.addEventListener('click', (event) => {
            const back = event.target.closest('[data-call-log-back]');
            if (back) {
                event.stopPropagation();
                this.callBack(Number(back.dataset.callLogBack), back.dataset.callType);
                return;
            }
            const row = event.target.closest('[data-call-log-chat]');
            if (row) this.openChat(Number(row.dataset.callLogChat));
        });

        this.el.list.addEventListener('contextmenu', (event) => {
            const row = event.target.closest('[data-call-log-ids]');
            if (!row) return;
            event.preventDefault();
            this.remove(row.dataset.callLogIds.split(',').map(Number));
        });

        let timer = null;
        this.el.list.addEventListener('touchstart', (event) => {
            const row = event.target.closest('[data-call-log-ids]');
            if (row) timer = setTimeout(() => this.remove(row.dataset.callLogIds.split(',').map(Number)), 500);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach((type) => this.el.list.addEventListener(type, () => clearTimeout(timer), { passive: true }));

        this.el.scroll.addEventListener('scroll', () => {
            const { scrollTop, scrollHeight, clientHeight } = this.el.scroll;
            if (this.hasMore && scrollHeight - scrollTop - clientHeight < 200) this.load();
        }, { passive: true });

        document.addEventListener('chat:sidebar-mode', (event) => {
            if (event.detail.mode !== 'calls' && !this.el.view.hidden) this.close({ silent: true });
        });

        document.addEventListener('app:back', (event) => {
            if (this.isOpen && !this.chat.active) {
                event.preventDefault();
                this.close();
            }
        });

        // A call just ended or a missed call arrived: refresh the list and the badge.
        document.addEventListener('call:state', (event) => {
            if (['ended', 'incoming-dismissed'].includes(event.detail.state)) this.refreshSoon();
        });
        document.addEventListener('chat:incoming', (event) => {
            const { message } = event.detail;
            if (message.type !== 'call') return;
            if (!message.seen_at && !this.isOpen) {
                this.unseen++;
                this.renderBadge();
            }
            this.refreshSoon();
        });
    }

    refreshSoon() {
        if (!this.isOpen) return;
        clearTimeout(this.refreshTimer);
        this.refreshTimer = setTimeout(() => this.load(true), 1200);
    }

    open() {
        if (this.chat.contactsPanel?.isOpen) this.chat.contactsPanel.close();
        if (this.chat.starred?.isOpen) this.chat.starred.close({ silent: true });
        this.el.sidebar.dataset.mode = 'calls';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'calls' } }));

        this.items = [];
        this.hasMore = false;
        this.el.list.innerHTML = '<div class="flex justify-center p-6 text-primary"><span class="spinner"></span></div>';
        this.load(true);
        this.markSeen();
    }

    close({ silent = false } = {}) {
        this.el.view.hidden = true;
        if (silent) return;
        this.el.chatsView.hidden = false;
        if (this.el.sidebar.dataset.mode === 'calls') this.el.sidebar.dataset.mode = 'chats';
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    async markSeen() {
        if (!this.unseen) return;
        this.unseen = 0;
        this.renderBadge();
        try {
            const { updated } = await this.chat.api.markCallsSeen();
            // Missed calls also counted as unread messages in their chats.
            if (updated) this.chat.loadConversations();
        } catch {
            /* the badge comes back on the next page load */
        }
    }

    renderBadge() {
        document.querySelectorAll('[data-calls-badge]').forEach((badge) => {
            badge.hidden = !this.unseen;
            badge.textContent = this.unseen > 99 ? '99+' : String(this.unseen);
        });
    }

    async load(reset = false) {
        if (this.loading) return;
        this.loading = true;
        this.el.more.hidden = reset;

        try {
            const before = reset ? null : this.items.at(-1)?.id;
            const data = await this.chat.api.callLog(before);
            this.items = reset ? data.data : [...this.items, ...data.data];
            this.hasMore = data.has_more;
            this.render();
        } catch (error) {
            this.el.list.innerHTML = T.emptyState({ iconName: 'circle-alert', title: "Couldn't load your calls", text: errorMessage(error) });
        } finally {
            this.loading = false;
            this.el.more.hidden = true;
        }
    }

    render() {
        if (!this.items.length) {
            this.el.list.innerHTML = T.emptyState({
                iconName: 'phone',
                title: 'No calls yet',
                text: 'Start a voice or video call from any chat. Your calls will show up here.',
            });
            return;
        }

        this.el.list.innerHTML = groupCalls(this.items)
            .map(({ calls }) => {
                const call = calls[0];
                const peer = this.chat.decorate({ ...(call.peer ?? {}), name: call.peer?.saved_name || call.peer?.name || 'Unknown' });
                const arrow = call.missed ? 'phone-missed' : call.direction === 'outgoing' ? 'phone-outgoing' : 'phone-incoming';
                const video = call.type === 'video';

                return html`
                    <div class="calls-entry${call.missed ? ' is-missed' : ''}" data-call-log-chat="${call.conversation_id}" data-call-log-ids="${calls.map((c) => c.id).join(',')}" role="button" tabindex="0">
                        ${raw(T.avatar(peer, 'md'))}
                        <span class="calls-entry-body">
                            <span class="calls-entry-name">${peer.name}${calls.length > 1 ? ` (${calls.length})` : ''}</span>
                            <span class="calls-entry-detail">${raw(icon(arrow))}${callLogDetail(call)}</span>
                        </span>
                        <button type="button" class="btn-icon calls-entry-back" data-call-log-back="${call.conversation_id}" data-call-type="${call.type}"
                                aria-label="${video ? 'Video call' : 'Voice call'} ${peer.name}" title="${video ? 'Video call' : 'Voice call'}">${raw(icon(video ? 'video' : 'phone'))}</button>
                    </div>
                `;
            })
            .join('');
    }

    async ensureConversation(conversationId) {
        if (this.chat.conversations.has(conversationId)) return true;
        return Boolean(await this.chat.refreshConversation(conversationId));
    }

    async callBack(conversationId, type) {
        if (!(await this.ensureConversation(conversationId))) {
            toast.error('This chat is not available.');
            return;
        }
        this.chat.calls?.startCall(conversationId, type === 'video' ? 'video' : 'audio');
    }

    openChat(conversationId) {
        this.close();
        this.chat.openConversation(conversationId);
    }

    async remove(ids) {
        const choice = await confirmDialog({
            title: ids.length > 1 ? `Remove these ${ids.length} calls?` : 'Remove this call?',
            message: 'It is removed from your call log and chat only. The other person still sees it.',
            icon: 'trash-2',
            actions: [{ label: 'Remove', value: 'remove', variant: 'danger' }],
        });
        if (choice !== 'remove') return;

        try {
            for (const id of ids) await this.chat.api.removeCall(id);
            this.items = this.items.filter((call) => !ids.includes(call.id));
            this.render();
            this.chat.loadConversations();
        } catch (error) {
            toast.error(errorMessage(error, 'The call could not be removed.'));
            this.load(true);
        }
    }

    async clearAll() {
        const choice = await confirmDialog({
            title: 'Clear call log?',
            message: 'All calls are removed from your call log and chats. The other people still see them.',
            icon: 'trash-2',
            actions: [{ label: 'Clear call log', value: 'clear', variant: 'danger' }],
        });
        if (choice !== 'clear') return;

        try {
            await this.chat.api.clearCalls();
            this.items = [];
            this.hasMore = false;
            this.render();
            this.chat.loadConversations();
            toast.success('Call log cleared.');
        } catch (error) {
            toast.error(errorMessage(error, 'The call log could not be cleared.'));
        }
    }
}
