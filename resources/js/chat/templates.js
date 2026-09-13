/**
 * HTML builders for the chat UI.
 *
 * SECURITY: every user-controlled value is escaped via the html`` tag.
 * raw() is only used for markup generated in this file or icon SVGs.
 */
import { escapeHtml, formatBytes, formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatDayLabel, formatListTime, formatTime } from './format';

/* ------------------------------------------------------------------ */
/* Avatars                                                             */
/* ------------------------------------------------------------------ */

export function avatar(user, size = 'md', { status = false } = {}) {
    if (!user) return '';
    const online = status && user.is_online;
    const inner = user.avatar_url
        ? html`<img class="avatar-img" src="${user.avatar_url}" alt="" loading="lazy" decoding="async">`
        : html`<span class="avatar-fallback" style="--hue: ${Number(user.avatar_hue) || 0}">${user.initials || '?'}</span>`;

    return html`<span class="avatar avatar-${size}${online ? ' is-online' : ''}" data-avatar-user="${user.id}">${raw(inner)}${raw(
        status ? '<span class="avatar-status" aria-hidden="true"></span>' : '',
    )}</span>`;
}

/* ------------------------------------------------------------------ */
/* Message status ticks                                                */
/* ------------------------------------------------------------------ */

export function statusTicks(status, { pop = false } = {}) {
    const extra = pop ? ' is-pop' : '';
    switch (status) {
        case 'pending':
            return `<span class="tick" title="Sending…">${icon('clock')}</span>`;
        case 'failed':
            return `<span class="tick" title="Not sent">${icon('circle-alert')}</span>`;
        case 'seen':
            return `<span class="tick is-seen${extra}" title="Seen">${icon('check-check')}</span>`;
        case 'delivered':
            return `<span class="tick${extra}" title="Delivered">${icon('check-check')}</span>`;
        default:
            return `<span class="tick${extra}" title="Sent">${icon('check')}</span>`;
    }
}

/* ------------------------------------------------------------------ */
/* Sidebar                                                             */
/* ------------------------------------------------------------------ */

export function conversationItem(conversation, { active = false, typing = false } = {}) {
    const user = conversation.participant ?? {};
    const last = conversation.last_message;
    const unread = conversation.unread_count || 0;

    let preview = '';
    if (typing) {
        preview = html`<span class="conversation-preview-text is-typing">typing…</span>`;
    } else if (last) {
        const ticks = last.is_mine && !last.is_deleted ? statusTicks(last.status) : '';
        const prefix = last.is_mine && !last.is_deleted ? 'You: ' : '';
        preview =
            raw(ticks).value +
            html`<span class="conversation-preview-text${last.is_deleted ? ' is-deleted' : ''}">${prefix}${last.preview}</span>`;
    }

    const classes = ['conversation-item', active && 'is-active', unread > 0 && 'has-unread'].filter(Boolean).join(' ');

    return html`
        <button type="button" class="${classes}" data-conversation-id="${conversation.id}" aria-current="${active ? 'true' : 'false'}">
            ${raw(avatar(user, 'md', { status: true }))}
            <span class="conversation-body">
                <span class="conversation-row">
                    <span class="conversation-name">${user.name ?? 'Unknown user'}</span>
                    <span class="conversation-time">${last ? formatListTime(last.created_at) : ''}</span>
                </span>
                <span class="conversation-row">
                    <span class="conversation-preview">${raw(preview)}</span>
                    <span class="conversation-badges">
                        ${raw(conversation.blocked_by_me ? `<span class="badge badge-danger" title="Blocked">${icon('ban', 'icon-xs')}</span>` : '')}
                        ${raw(unread > 0 ? html`<span class="badge badge-primary badge-pop">${unread > 99 ? '99+' : unread}</span>` : '')}
                    </span>
                </span>
            </span>
        </button>
    `;
}

export function searchResultItem(user) {
    return html`
        <button type="button" class="conversation-item" data-start-user-id="${user.id}">
            ${raw(avatar(user, 'md', { status: true }))}
            <span class="conversation-body">
                <span class="conversation-name">${user.name}</span>
                <span class="search-result-meta">@${user.username}</span>
            </span>
            ${raw(icon('message-square-plus', 'text-subtle'))}
        </button>
    `;
}

export function onlineUser(user) {
    const first = String(user.name || '').split(' ')[0];
    return html`
        <button type="button" class="online-user" data-start-user-id="${user.id}" title="${user.name}">
            ${raw(avatar(user, 'lg', { status: true }))}
            <span class="online-user-name">${first}</span>
        </button>
    `;
}

export function sectionTitle(text) {
    return html`<div class="sidebar-section-title">${text}</div>`;
}

export function emptyState({ iconName, title, text }) {
    return html`
        <div class="empty-state">
            <div class="empty-state-icon">${raw(icon(iconName))}</div>
            <div class="empty-state-title">${title}</div>
            <div class="empty-state-text">${text}</div>
        </div>
    `;
}

export function chatHeaderUser(user) {
    return html`
        ${raw(avatar(user, 'md', { status: true }))}
        <div class="chat-header-info">
            <div class="chat-header-name">${user.name}</div>
            <div class="chat-header-status" data-chat-status></div>
        </div>
    `;
}

/* ------------------------------------------------------------------ */
/* Messages                                                            */
/* ------------------------------------------------------------------ */

const URL_PATTERN = /\bhttps?:\/\/[^\s<>"']+[^\s<>"'.,:;!?)\]]/gi;

/** Escape text, then turn http(s) URLs into safe links. */
export function formatMessageText(text) {
    return escapeHtml(text).replace(
        URL_PATTERN,
        (url) => `<a href="${url}" target="_blank" rel="noopener noreferrer nofollow ugc">${url}</a>`,
    );
}

const EMOJI_ONLY = /^(?:\p{Extended_Pictographic}|\p{Emoji_Modifier}|\u200D|\uFE0F|[\u{1F1E6}-\u{1F1FF}]|\s)+$/u;

export function isJumboEmoji(text) {
    if (!text || text.length > 24 || !EMOJI_ONLY.test(text) || !/\p{Extended_Pictographic}/u.test(text)) return false;
    const segments = typeof Intl.Segmenter === 'function'
        ? [...new Intl.Segmenter(undefined, { granularity: 'grapheme' }).segment(text.trim())].length
        : [...text.trim()].length;
    return segments <= 3;
}

export function dateDivider(value) {
    return html`<div class="date-divider" data-date-divider>${formatDayLabel(value)}</div>`;
}

function messageMeta(message) {
    const parts = [];
    if (message.is_edited) parts.push('<span class="message-edited">Edited</span>');
    parts.push(html`<time datetime="${message.created_at}">${formatTime(message.created_at)}</time>`);
    if (message.is_mine && !message.is_deleted) parts.push(statusTicks(message.status));
    return `<span class="message-meta" data-message-meta>${parts.join('')}</span>`;
}

/**
 * Client-side preview text for the recent chats list (mirrors Message::preview()).
 */
export function previewOf(message) {
    if (message.is_deleted) return 'This message was deleted';
    switch (message.type) {
        case 'image':
            return `📷 ${message.body || 'Photo'}`;
        case 'document':
            return `📄 ${message.attachment?.name || 'Document'}`;
        case 'voice':
            return '🎤 Voice message';
        default: {
            const text = String(message.body || '').replace(/\s+/g, ' ').trim();
            return text.length > 80 ? `${text.slice(0, 80)}…` : text;
        }
    }
}

/* Context supplied by the chat app (current user & participant names). */
const context = { meId: null, nameOf: () => '' };

export function setTemplateContext({ meId, nameOf }) {
    context.meId = meId;
    context.nameOf = nameOf;
}

const EXTENSION_LABELS = { pdf: 'PDF', doc: 'DOC', docx: 'DOCX' };

function replyQuote(message) {
    const reply = message.reply_to;
    if (!reply || message.is_deleted) return '';

    const author = Number(reply.sender_id) === Number(context.meId) ? 'You' : context.nameOf(reply.sender_id) || 'Them';

    return html`
        <button type="button" class="reply-quote${reply.is_deleted ? ' is-deleted' : ''}" data-jump-to="${reply.id}">
            <span class="reply-quote-author">${author}</span>
            <span class="reply-quote-text">${reply.preview}</span>
        </button>
    `;
}

function uploadOverlay(message) {
    if (!message.uploading) return '';
    return html`<span class="upload-overlay"><span class="spinner"></span><span class="upload-bar"><span data-upload-progress style="width: ${Math.round((message.progress || 0) * 100)}%"></span></span></span>`;
}

function imageAttachment(message) {
    const a = message.attachment;
    const ratio = a.width && a.height ? `${a.width} / ${a.height}` : '4 / 3';
    const src = a.local_url || a.thumbnail_url || a.url;

    return html`
        <button type="button" class="message-image" data-lightbox="${a.local_url || a.url}" data-lightbox-name="${a.name}"
                data-lightbox-download="${a.download_url || ''}" style="aspect-ratio: ${ratio}" aria-label="Open image">
            <img src="${src}" alt="${a.name || 'Photo'}" loading="lazy" decoding="async">
            ${raw(uploadOverlay(message))}
        </button>
    `;
}

function documentAttachment(message) {
    const a = message.attachment;
    const extension = String(a.name || '').split('.').pop().toLowerCase();
    const label = EXTENSION_LABELS[extension] ?? extension.toUpperCase();
    const inner = html`
        <span class="message-file-icon" data-ext="${extension}">${raw(icon('file-text'))}</span>
        <span class="message-file-body">
            <span class="message-file-name">${a.name}</span>
            <span class="message-file-meta">${label} · ${formatBytes(a.size)}</span>
        </span>
        ${raw(message.uploading ? '<span class="spinner"></span>' : `<span class="message-file-download">${icon('download')}</span>`)}
    `;

    return message.uploading || !a.download_url
        ? html`<div class="message-file">${raw(inner)}${raw(uploadOverlay(message))}</div>`
        : html`<a class="message-file" href="${a.download_url}" download="${a.name}" title="Download ${a.name}">${raw(inner)}</a>`;
}

function voiceAttachment(message) {
    const a = message.attachment;
    const bars = Array.from({ length: 28 }, (_, i) => {
        const seed = ((Number(String(message.id).replace(/\D/g, '')) || 7) * (i + 3) * 37) % 100;
        return `<span style="height: ${22 + (seed % 70)}%"></span>`;
    }).join('');

    return html`
        <div class="voice-player" data-voice-player data-voice-src="${a.local_url || a.url}" data-duration="${a.duration || 0}">
            <button type="button" class="voice-toggle" data-voice-toggle aria-label="Play voice message" ${raw(message.uploading ? 'disabled' : '')}>
                ${raw(icon('play', 'voice-icon-play'))}${raw(icon('pause', 'voice-icon-pause'))}
            </button>
            <span class="voice-wave" data-voice-seek>${raw(bars)}<span class="voice-wave-progress" data-voice-progress>${raw(bars)}</span></span>
            <span class="voice-time" data-voice-time>${formatDuration(a.duration)}</span>
            ${raw(uploadOverlay(message))}
        </div>
    `;
}

function messageContent(message) {
    if (message.is_deleted) {
        return `<span class="message-deleted">${icon('ban')}${message.is_mine ? 'You deleted this message' : 'This message was deleted'}</span>`;
    }

    let attachment = '';
    if (message.attachment) {
        attachment =
            message.type === 'image' ? imageAttachment(message)
            : message.type === 'voice' ? voiceAttachment(message)
            : documentAttachment(message);
    }

    const body = message.body ?? '';
    const text = body ? `<div class="message-text${!attachment && isJumboEmoji(body) ? ' is-jumbo' : ''}">${formatMessageText(body)}</div>` : '';

    return replyQuote(message) + attachment + text;
}

export function messageBubble(message) {
    const mediaOnly = !message.is_deleted && message.type === 'image' && message.attachment && !message.body;
    const isRealId = typeof message.id === 'number';

    const classes = [
        'message',
        message.is_mine ? 'is-mine' : 'is-theirs',
        `is-type-${message.type || 'text'}`,
        message.status === 'pending' && 'is-pending',
        message.status === 'failed' && 'is-failed',
        message.is_deleted && 'is-deleted',
        mediaOnly && 'is-media-only',
    ]
        .filter(Boolean)
        .join(' ');

    const retry =
        message.status === 'failed'
            ? html`<button type="button" class="message-retry" data-retry="${message.client_id}">${raw(icon('refresh-cw'))} Tap to retry</button>`
            : '';

    const menu = isRealId
        ? `<button type="button" class="message-menu-btn" data-message-menu aria-label="Message options">${icon('chevron-down')}</button>`
        : '';

    return html`
        <div class="${classes}" data-message-id="${message.id}" data-sender-id="${message.sender_id}">
            <div class="message-bubble">
                ${raw(menu)}
                ${raw(messageContent(message))}
                ${raw(messageMeta(message))}
                ${raw(retry)}
            </div>
        </div>
    `;
}

export function composerContext({ mode, title, preview }) {
    return html`
        <div class="composer-context" data-mode="${mode}">
            <span class="composer-context-icon">${raw(icon(mode === 'edit' ? 'pencil' : 'corner-up-left'))}</span>
            <span class="composer-context-body">
                <span class="composer-context-title">${title}</span>
                <span class="composer-context-text">${preview}</span>
            </span>
            <button type="button" class="btn-icon btn-icon-sm" data-cancel-context aria-label="Cancel">${raw(icon('x'))}</button>
        </div>
    `;
}

export function attachmentPreview({ type, name, size, url }) {
    const thumb =
        type === 'image'
            ? html`<img class="attachment-preview-thumb" src="${url}" alt="">`
            : html`<span class="message-file-icon">${raw(icon('file-text'))}</span>`;

    return html`
        <div class="attachment-preview">
            ${raw(thumb)}
            <span class="composer-context-body">
                <span class="composer-context-title">${name}</span>
                <span class="composer-context-text">${type === 'image' ? 'Photo' : 'Document'} · ${formatBytes(size)} — add a caption (optional)</span>
            </span>
            <button type="button" class="btn-icon btn-icon-sm" data-remove-attachment aria-label="Remove attachment">${raw(icon('x'))}</button>
        </div>
    `;
}

export function messageMenu(items) {
    return items
        .map((item) =>
            item === '-'
                ? '<div class="dropdown-divider"></div>'
                : html`<button type="button" class="dropdown-item${item.danger ? ' is-danger' : ''}" data-message-action="${item.action}" role="menuitem">${raw(icon(item.icon))} ${item.label}</button>`,
        )
        .join('');
}

export function conversationIntro(user) {
    return html`
        <div class="conversation-intro" data-conversation-intro>
            ${raw(avatar(user, 'xl'))}
            <div class="conversation-intro-name">${user.name}</div>
            <div class="conversation-intro-meta">@${user.username}</div>
            <p class="conversation-intro-text">No messages yet. Say hello and start the conversation.</p>
            <button type="button" class="btn btn-secondary btn-sm" data-action="say-hi">👋 Say hi</button>
        </div>
    `;
}

export function historyStart() {
    return `<div class="history-start" data-history-start>${icon('lock', 'icon-xs')} Messages are private between you and this person.</div>`;
}

export function messageSkeletons() {
    const rows = [
        ['theirs', 48],
        ['mine', 36],
        ['theirs', 62],
        ['mine', 54],
        ['theirs', 30],
        ['mine', 44],
    ];
    return rows
        .map(
            ([side, width]) => `
        <div class="message ${side === 'mine' ? 'is-mine' : ''}" aria-hidden="true">
            <div class="skeleton" style="height: 2.6rem; width: ${width}%; border-radius: 18px"></div>
        </div>`,
        )
        .join('');
}
