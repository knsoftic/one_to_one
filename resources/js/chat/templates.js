/**
 * HTML builders for the chat UI.
 *
 * SECURITY: every user-controlled value is escaped via the html`` tag.
 * raw() is only used for markup generated in this file or icon SVGs.
 */
import { formatBytes, formatDuration, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatDayLabel, formatListTime, formatTime } from './format';
import { formatMessageText, stripFormatting } from './formatting';
import { speedLabel, voiceSpeed } from './voice';

/* ------------------------------------------------------------------ */
/* Avatars                                                             */
/* ------------------------------------------------------------------ */

export function avatar(user, size = 'md', { status = false } = {}) {
    if (!user) return '';
    const online = status && user.is_online;
    // Broadcast lists (G9) and similar show an icon instead of letters.
    const inner = user.avatar_icon
        ? `<span class="avatar-fallback is-accent">${icon(user.avatar_icon)}</span>`
        : user.avatar_url
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

export function conversationItem(conversation, { active = false, typing = false, draft = '' } = {}) {
    const user = conversation.participant ?? {};
    const last = conversation.last_message;
    const unread = conversation.unread_count || 0;
    const isGroup = conversation.type === 'group';

    let preview = '';
    if (typing) {
        // Groups say who: "Sara is typing…".
        const action = typeof typing === 'object' ? typing.action : typing;
        const verb = action === 'recording' ? 'recording audio…' : 'typing…';
        const text = typeof typing === 'object' && typing.name ? `${typing.name} is ${verb}` : verb;
        preview = html`<span class="conversation-preview-text is-typing">${text}</span>`;
    } else if (draft && draft.trim()) {
        preview = html`<span class="conversation-preview-text"><span class="conversation-draft">Draft:</span> ${stripFormatting(draft).replace(/\s+/g, ' ').trim()}</span>`;
    } else if (last) {
        const plainMine = last.is_mine && !last.is_deleted && last.type !== 'call' && last.type !== 'system';
        const ticks = plainMine ? statusTicks(last.status) : '';
        let prefix = plainMine ? 'You: ' : '';
        if (isGroup && !last.is_mine && last.type !== 'system' && last.type !== 'call') {
            prefix = `${context.nameOf(last.sender_id) || last.sender_name || 'Someone'}: `;
        }
        const text = last.type === 'system' && last.system ? systemNoticeText(last.system, last.sender_id) : last.preview;
        preview =
            raw(ticks).value +
            html`<span class="conversation-preview-text${last.is_deleted ? ' is-deleted' : ''}">${prefix}${text}</span>`;
    }

    // My own settings for this chat (Phase 2).
    const settings = conversation.settings ?? {};
    const muted = isChatMuted(conversation);
    const markedUnread = unread === 0 && Boolean(settings.marked_unread);
    const classes = ['conversation-item', active && 'is-active', (unread > 0 || markedUnread) && 'has-unread', muted && 'is-muted'].filter(Boolean).join(' ');

    let unreadBadge = '';
    if (unread > 0) unreadBadge = html`<span class="badge ${muted ? 'badge-muted' : 'badge-primary'} badge-pop">${unread > 99 ? '99+' : unread}</span>`;
    else if (markedUnread) unreadBadge = '<span class="badge badge-primary badge-dot" title="Marked as unread"></span>';

    return html`
        <div class="conversation-entry">
            <button type="button" class="${classes}" data-conversation-id="${conversation.id}" aria-current="${active ? 'true' : 'false'}">
                ${raw(avatar(user, 'md', { status: !isGroup && !user.is_group }))}
                <span class="conversation-body">
                    <span class="conversation-row">
                        <span class="conversation-name">${user.name ?? 'Unknown user'}</span>
                        <span class="conversation-time">${last ? formatListTime(last.created_at) : ''}</span>
                    </span>
                    <span class="conversation-row">
                        <span class="conversation-preview">${raw(preview)}</span>
                        <span class="conversation-badges">
                            ${raw(conversation.blocked_by_me ? `<span class="badge badge-danger" title="Blocked">${icon('ban', 'icon-xs')}</span>` : '')}
                            ${raw(muted ? `<span class="conversation-flag" title="Muted">${icon('bell-off')}</span>` : '')}
                            ${raw(settings.pinned ? `<span class="conversation-flag" title="Pinned">${icon('pin')}</span>` : '')}
                            ${raw(conversation.unread_mentions > 0 ? `<span class="badge badge-primary badge-mention" title="You were mentioned">@</span>` : '')}
                            ${raw(unreadBadge)}
                        </span>
                    </span>
                </span>
            </button>
            <button type="button" class="conversation-menu-btn" data-chat-menu="${conversation.id}" aria-label="Chat options" title="Chat options">${raw(icon('chevron-down'))}</button>
        </div>
    `;
}

/** Muted right now (a timed mute ends by itself). */
export function isChatMuted(conversation, now = Date.now()) {
    const settings = conversation?.settings;
    if (!settings?.muted) return false;
    return !settings.muted_until || Date.parse(settings.muted_until) > now;
}

/** "Archived" folder row at the top of the chat list (C3). */
export function archivedRow(count, unreadChats) {
    return html`
        <button type="button" class="archived-row" data-open-archived>
            <span class="archived-row-icon">${raw(icon('archive'))}</span>
            <span class="archived-row-label">Archived</span>
            <span class="archived-row-count">${raw(unreadChats ? html`<span class="badge badge-primary">${unreadChats}</span>` : String(count))}</span>
        </button>
    `;
}

export function archivedHeader() {
    return html`
        <div class="archived-header">
            <button type="button" class="btn-icon" data-close-archived aria-label="Back to chats">${raw(icon('arrow-left'))}</button>
            <span class="archived-header-title">Archived</span>
        </div>
        <p class="archived-hint">These chats stay archived when new messages arrive.</p>
    `;
}

/** "Locked chats" folder row (C9): only the number, never names. */
export function lockedRow(count, unreadChats) {
    return html`
        <button type="button" class="archived-row" data-open-locked>
            <span class="archived-row-icon">${raw(icon('lock-keyhole'))}</span>
            <span class="archived-row-label">Locked chats</span>
            <span class="archived-row-count">${raw(unreadChats ? html`<span class="badge badge-primary">${unreadChats}</span>` : String(count))}</span>
        </button>
    `;
}

export function lockedHeader() {
    return html`
        <div class="archived-header">
            <button type="button" class="btn-icon" data-close-locked aria-label="Lock and go back to chats">${raw(icon('arrow-left'))}</button>
            <span class="archived-header-title">Locked chats</span>
        </div>
        <p class="archived-hint">These chats open only with your secret code. Going back locks them again.</p>
    `;
}

export function searchResultItem(user) {
    return html`
        <button type="button" class="conversation-item" data-start-user-id="${user.id}">
            ${raw(avatar(user, 'md'))}
            <span class="conversation-body">
                <span class="conversation-name">${user.name}</span>
                <span class="search-result-meta">@${user.username}</span>
            </span>
            ${raw(icon('message-square-plus', 'text-subtle'))}
        </button>
    `;
}

/** "New group" at the top of the contacts panel (G1). */
export function newGroupItem() {
    return html`
        <button type="button" class="conversation-item contact-item new-group-item" data-action="new-group">
            <span class="avatar avatar-md"><span class="avatar-fallback is-accent">${raw(icon('users'))}</span></span>
            <span class="conversation-body">
                <span class="conversation-name">New group</span>
                <span class="search-result-meta">Chat with several people at once</span>
            </span>
        </button>
    `;
}

/** "Message yourself" at the top of the contacts panel (C7). */
export function messageYourselfItem(user) {
    return html`
        <button type="button" class="conversation-item contact-item" data-start-user-id="${user.id}">
            ${raw(avatar(user, 'md'))}
            <span class="conversation-body">
                <span class="conversation-name">${user.name} (You)</span>
                <span class="search-result-meta">Message yourself</span>
            </span>
        </button>
    `;
}

/** A phone contact who is not on the app yet, with an Invite button (C8). */
export function inviteContactItem({ name, phone, index }) {
    const parts = String(name).trim().split(/\s+/).map((part) => part.match(/[\p{L}\p{N}]/u)?.[0] ?? '').filter(Boolean);
    const initials = ((parts[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1] : '')).toUpperCase() || '#';

    return html`
        <div class="conversation-item contact-item invite-contact">
            <span class="avatar avatar-md"><span class="avatar-fallback is-neutral">${initials}</span></span>
            <span class="conversation-body">
                <span class="conversation-name">${name}</span>
                <span class="search-result-meta">${phone}</span>
            </span>
            <button type="button" class="btn btn-secondary btn-sm" data-invite-index="${index}">Invite</button>
        </div>
    `;
}

/** A saved phone-book contact (name as saved, profile name / username underneath). */
export function contactItem(contact, user = contact.user) {
    const secondary = contact.name !== user.name ? `~${user.name} · @${user.username}` : `@${user.username}`;

    return html`
        <button type="button" class="conversation-item contact-item" data-start-user-id="${user.id}">
            ${raw(avatar(user, 'md'))}
            <span class="conversation-body">
                <span class="conversation-name">${contact.name}</span>
                <span class="search-result-meta">${secondary}</span>
            </span>
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
        ${raw(avatar(user, 'md', { status: !user.is_group }))}
        <div class="chat-header-info">
            <div class="chat-header-name">${user.name}</div>
            <div class="chat-header-status" data-chat-status></div>
        </div>
    `;
}

/* ------------------------------------------------------------------ */
/* Messages                                                            */
/* ------------------------------------------------------------------ */

/** Escaped text with WhatsApp-style formatting and safe links (see formatting.js). */
export { formatMessageText };

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
    if (message.expires_at && !message.is_deleted) parts.push(`<span class="message-timer" title="Disappears ${formatTime(message.expires_at)}">${icon('timer')}</span>`);
    if (message.is_starred && !message.is_deleted) parts.push(`<span class="message-star" title="Starred">${icon('star')}</span>`);
    if (message.is_edited) parts.push('<span class="message-edited">Edited</span>');
    parts.push(html`<time datetime="${message.created_at}">${formatTime(message.created_at)}</time>`);
    if (message.is_mine && !message.is_deleted && message.type !== 'call') parts.push(statusTicks(message.status));
    return `<span class="message-meta" data-message-meta>${parts.join('')}</span>`;
}

const MISSED_CALL_REASONS = ['missed', 'cancelled', 'busy'];

/**
 * How a call-history entry reads for the current user (the sender is the caller).
 */
export function callSummary(message) {
    const call = message.call ?? {};
    const video = call.type === 'video';
    const kind = video ? 'video call' : 'voice call';
    const Kind = video ? 'Video call' : 'Voice call';
    const missed = !message.is_mine && MISSED_CALL_REASONS.includes(call.reason);
    const duration = call.reason === 'completed' && call.duration != null ? formatDuration(call.duration) : '';

    if (message.is_mine) {
        const outcome = { missed: 'No answer', cancelled: 'Cancelled', busy: 'Busy', declined: 'Declined', failed: "Couldn't connect" };
        return { title: Kind, detail: duration || outcome[call.reason] || '', iconName: video ? 'video' : 'phone-outgoing', missed, video };
    }

    if (missed) {
        return { title: `Missed ${kind}`, detail: 'Tap to call back', iconName: 'phone-missed', missed, video };
    }

    return {
        title: call.reason === 'declined' ? `Declined ${kind}` : Kind,
        detail: duration || (call.reason === 'failed' ? "Couldn't connect" : ''),
        iconName: video ? 'video' : 'phone-incoming',
        missed,
        video,
    };
}

function callHistory(message) {
    const summary = callSummary(message);

    return html`
        <button type="button" class="call-log${summary.missed ? ' is-missed' : ''}" data-action="call-back"
                data-call-type="${summary.video ? 'video' : 'audio'}" title="Call again">
            <span class="call-log-icon">${raw(icon(summary.iconName))}</span>
            <span class="call-log-body">
                <span class="call-log-title">${summary.title}</span>
                ${raw(summary.detail ? html`<span class="call-log-detail">${summary.detail}</span>` : '')}
            </span>
        </button>
    `;
}

/**
 * Client-side preview text for the recent chats list (mirrors Message::preview()).
 */
/**
 * Text of an app notice, with "You" and names as saved by the reader (group notices, Phase 4).
 */
export function systemNoticeText(system, actorId = null) {
    if (!system) return '';
    const me = Number(context.meId);
    const actor = system.actor ?? (actorId ? { id: actorId } : null);
    const who = (person, capital = true) => {
        if (!person) return capital ? 'Someone' : 'someone';
        if (Number(person.id) === me) return capital ? 'You' : 'you';
        return context.nameOf(person.id) || person.name || (capital ? 'Someone' : 'someone');
    };
    const a = who(actor);
    const users = (system.users ?? []).map((u) => who(u, false)).join(', ');
    const allow = (onlyAdmins) => (onlyAdmins ? 'only admins' : 'all members');

    switch (system.event) {
        case 'group_created': return `${a} created group "${system.name ?? ''}"`;
        case 'members_added': return `${a} added ${users}`;
        case 'member_removed': return `${a} removed ${users}`;
        case 'member_left': return `${a} left`;
        case 'member_joined_link': return `${a} joined using this group's invite link`;
        case 'name_changed': return `${a} changed the group name to "${system.name ?? ''}"`;
        case 'description_changed': return `${a} changed the group description`;
        case 'avatar_changed': return `${a} changed this group's icon`;
        case 'avatar_removed': return `${a} deleted this group's icon`;
        case 'settings_changed':
            return 'only_admins_send' in system
                ? `${a} changed this group's settings to allow ${allow(system.only_admins_send)} to send messages`
                : `${a} changed this group's settings to allow ${allow(system.only_admins_edit)} to edit this group's info`;
        case 'group_ended': return `${a} deleted this group`;
        case 'community_created': return `${a} created the community "${system.name ?? ''}"`;
        case 'community_linked': return `${a} added this group to the community "${system.name ?? ''}"`;
        case 'community_unlinked': return `${a} removed this group from the community "${system.name ?? ''}"`;
        case 'member_joined_community': return `${a} joined from the community`;
        default: return system.text ?? '';
    }
}

export function previewOf(message) {
    if (message.is_deleted) return 'This message was deleted';
    if (message.view_once || message.attachment?.view_once) {
        return { video: '🎥 View once video', voice: '🎤 View once voice message' }[message.type] ?? '📷 View once photo';
    }
    switch (message.type) {
        case 'image':
            return isGif(message.attachment) ? `👾 ${message.body || 'GIF'}` : `📷 ${message.body || 'Photo'}`;
        case 'sticker':
            return '💟 Sticker';
        case 'location':
            return message.location?.live ? '📍 Live location' : '📍 Location';
        case 'contact':
            return `👤 Contact: ${message.contact?.name ?? ''}`;
        case 'poll':
            return `📊 Poll: ${message.poll?.question ?? ''}`;
        case 'system':
            return systemNoticeText(message.system, message.sender_id);
        case 'video':
            return `🎥 ${message.body || 'Video'}`;
        case 'document':
            return `📄 ${message.attachment?.name || 'Document'}`;
        case 'voice':
            return '🎤 Voice message';
        case 'call': {
            const summary = callSummary(message);
            return `${summary.video ? '📹' : '📞'} ${summary.title}`;
        }
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

const EXTENSION_LABELS = {
    pdf: 'PDF', doc: 'Word', docx: 'Word', odt: 'Document', rtf: 'RTF', txt: 'Text',
    xls: 'Excel', xlsx: 'Excel', ods: 'Spreadsheet', csv: 'CSV',
    ppt: 'PowerPoint', pptx: 'PowerPoint', odp: 'Presentation',
    zip: 'ZIP', rar: 'RAR', '7z': '7Z', mp3: 'MP3 audio', m4a: 'M4A audio',
};

/** S4: the status update a reply or reaction answers. */
function statusQuote(message) {
    const quote = message.status_quote;
    if (!quote || message.is_deleted) return '';

    const owner = Number(quote.owner_id) === Number(context.meId) ? 'You' : context.nameOf(quote.owner_id) || 'Status';
    const text = quote.text || (quote.type === 'video' ? 'Video' : quote.type === 'image' ? 'Photo' : '');
    const picture = quote.thumbnail_url
        ? html`<img class="status-quote-thumb" src="${quote.thumbnail_url}" alt="" loading="lazy">`
        : quote.type === 'text'
          ? html`<span class="status-quote-thumb status-text is-bg-${quote.background || 'teal'}">Aa</span>`
          : `<span class="status-quote-thumb is-icon">${icon(quote.type === 'video' ? 'film' : 'image')}</span>`;

    return html`
        <div class="status-quote${quote.available ? '' : ' is-expired'}">
            <span class="status-quote-body">
                <span class="status-quote-author">${raw(icon('circle-dashed', 'icon-xs'))} ${owner} · ${quote.reaction ? 'Status reaction' : 'Status'}</span>
                <span class="status-quote-text">${text}</span>
            </span>
            ${raw(picture)}
        </div>
    `;
}

function replyQuote(message) {
    const reply = message.reply_to;
    if (!reply || message.is_deleted) return '';

    const author = Number(reply.sender_id) === Number(context.meId) ? 'You' : context.nameOf(reply.sender_id) || 'Them';
    // A private reply to a group message (G7): "Sara · Family".
    const elsewhere = reply.conversation_id && Number(reply.conversation_id) !== Number(message.conversation_id);

    return html`
        <button type="button" class="reply-quote${reply.is_deleted ? ' is-deleted' : ''}" data-jump-to="${reply.id}" data-jump-conversation="${elsewhere ? reply.conversation_id : ''}">
            <span class="reply-quote-author">${author}${reply.group_name ? ` · ${reply.group_name}` : ''}</span>
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
            ${raw(a.hd ? '<span class="message-image-hd" title="Sent in HD">HD</span>' : '')}
            ${raw(isGif(a) ? '<span class="message-image-hd is-gif">GIF</span>' : '')}
            ${raw(uploadOverlay(message))}
        </button>
    `;
}

const isGif = (attachment) => Boolean(attachment?.animated || attachment?.mime === 'image/gif');

/** Location or live location card (M18). No map tiles are loaded from other sites. */
function locationCard(message) {
    const l = message.location ?? {};
    const lat = Number(l.lat) || 0;
    const lng = Number(l.lng) || 0;
    const until = l.live_until ? Date.parse(l.live_until) : 0;
    const active = Boolean(l.live && l.live_active && !l.stopped_at && until > Date.now());
    const href = `https://www.google.com/maps/search/?api=1&query=${lat.toFixed(6)},${lng.toFixed(6)}`;

    let title = 'Location';
    let detail = `${lat.toFixed(5)}, ${lng.toFixed(5)}${l.accuracy ? ` · ±${Number(l.accuracy)} m` : ''}`;
    if (l.live && active) {
        title = 'Live location';
        detail = `Until ${formatTime(l.live_until)}${l.updated_at ? ` · updated ${formatTime(l.updated_at)}` : ''}`;
    } else if (l.live) {
        title = 'Live location ended';
        detail = l.updated_at ? `Last updated ${formatTime(l.updated_at)}` : detail;
    }

    const stop = active && message.is_mine && typeof message.id === 'number'
        ? html`<button type="button" class="btn btn-sm location-stop" data-location-stop="${message.id}">Stop sharing</button>`
        : '';

    return html`
        <div class="location-card${active ? ' is-live' : ''}">
            <a class="location-map" href="${href}" target="_blank" rel="noopener noreferrer" aria-label="Open location in Maps">
                <span class="location-pin">${raw(icon(l.live ? 'navigation' : 'map-pin'))}</span>
            </a>
            <div class="location-body">
                <span class="location-title">${title}</span>
                <span class="location-detail">${detail}</span>
            </div>
            <div class="location-actions">
                <a class="btn btn-sm location-open" href="${href}" target="_blank" rel="noopener noreferrer">Open in Maps</a>
                ${raw(stop)}
            </div>
        </div>
    `;
}

/** Poll (M20): question, options with bars and counts; tapping an option votes. */
function pollCard(message) {
    const p = message.poll ?? { options: [] };
    const me = Number(context.meId);
    const total = Number(p.total_voters) || 0;
    const canVote = typeof message.id === 'number';
    const most = Math.max(0, ...p.options.map((option) => option.count));

    const options = p.options
        .map((option) => {
            const mine = option.voter_ids.map(Number).includes(me);
            const percent = total ? Math.round((option.count / total) * 100) : 0;
            const voters = option.voter_ids
                .map((id) => (Number(id) === me ? 'You' : context.nameOf(id) || 'Them'))
                .join(', ');

            return html`
                <button type="button" class="poll-option${mine ? ' is-mine' : ''}${option.count && option.count === most ? ' is-leading' : ''}" data-poll-option="${option.id}"
                        role="${p.multiple ? 'checkbox' : 'radio'}" aria-checked="${mine ? 'true' : 'false'}" ${raw(canVote ? '' : 'disabled')}
                        title="${voters}">
                    <span class="poll-check">${raw(mine ? icon('check') : '')}</span>
                    <span class="poll-option-main">
                        <span class="poll-option-row"><span class="poll-option-text">${option.text}</span><span class="poll-count">${option.count}</span></span>
                        <span class="poll-bar"><span style="width: ${percent}%"></span></span>
                    </span>
                </button>
            `;
        })
        .join('');

    return html`
        <div class="poll-card" data-poll="${message.id}">
            <div class="poll-question">${p.question}</div>
            <div class="poll-hint">${raw(icon(p.multiple ? 'list-checks' : 'circle-dot'))} ${p.multiple ? 'Select one or more' : 'Select one'}</div>
            <div class="poll-options-list" role="${p.multiple ? 'group' : 'radiogroup'}" aria-label="${p.question}">${raw(options)}</div>
            <div class="poll-total">${total === 1 ? '1 vote' : `${total} votes`}</div>
        </div>
    `;
}

/** Contact card (M19): name, numbers, "Message" when they use the app, "Save contact". */
function contactCard(message) {
    const c = message.contact ?? {};
    const phones = Array.isArray(c.phones) ? c.phones : [];
    const initials = String(c.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => [...part][0]).join('').toUpperCase();
    const canMessage = c.user && Number(c.user.id) !== Number(context.meId);

    return html`
        <div class="contact-card">
            <div class="contact-card-head">
                <span class="contact-avatar">${initials}</span>
                <span class="contact-card-body">
                    <strong class="contact-card-name">${c.name}</strong>
                    ${raw(phones.map((phone) => html`<span class="contact-card-phone">${phone}</span>`).join(''))}
                    ${raw(c.user ? html`<span class="contact-card-user">${raw(icon('message-circle'))} @${c.user.username}</span>` : '')}
                </span>
            </div>
            <div class="contact-card-actions">
                ${raw(canMessage ? html`<button type="button" class="contact-card-action" data-contact-message="${c.user.id}">Message</button>` : '')}
                ${raw(c.vcard_url ? html`<a class="contact-card-action" href="${c.vcard_url}" download>Save contact</a>` : '')}
                ${raw(phones[0] ? html`<a class="contact-card-action" href="tel:${phones[0].replace(/[^+\d]/g, '')}">Call</a>` : '')}
            </div>
        </div>
    `;
}

/** View once photo / video / voice (M22): never shows the media in the chat. */
function viewOnceBubble(message) {
    const label = { image: 'Photo', video: 'Video', voice: 'Voice message' }[message.type] ?? 'Media';
    const opened = Boolean(message.view_once?.opened_at) || (!message.is_mine && message.view_once?.available === false);
    const badge = `<span class="view-once-badge${opened ? ' is-opened' : ''}" aria-hidden="true">1</span>`;

    if (!message.is_mine && !opened && typeof message.id === 'number') {
        return html`<button type="button" class="view-once" data-view-once="${message.id}" aria-label="Open view once ${label.toLowerCase()}">${raw(badge)}<span>${label}</span></button>`;
    }

    return html`<span class="view-once is-static${opened ? ' is-opened' : ''}">${raw(badge)}<span>${opened ? 'Opened' : label}</span></span>`;
}

function stickerAttachment(message) {
    const a = message.attachment;
    return html`<span class="message-sticker"><img src="${a.local_url || a.url}" alt="Sticker" width="512" height="512" loading="lazy" decoding="async"></span>`;
}

function videoAttachment(message) {
    const a = message.attachment;
    const ratio = a.width && a.height ? `${Number(a.width)} / ${Number(a.height)}` : '16 / 9';
    const poster = a.local_thumbnail_url || a.thumbnail_url;
    const canPlay = !message.uploading && Boolean(a.local_url || a.url);

    return html`
        <button type="button" class="message-video${poster ? '' : ' no-poster'}" style="aspect-ratio: ${ratio}"
                ${raw(canPlay ? html`data-video="${a.local_url || a.url}" data-video-name="${a.name || 'Video'}" data-video-download="${a.download_url || ''}" data-video-poster="${poster || ''}"` : 'disabled')}
                aria-label="Play video${a.duration ? `, ${formatDuration(a.duration)}` : ''}">
            ${raw(poster ? html`<img src="${poster}" alt="" loading="lazy" decoding="async">` : `<span class="message-video-placeholder">${icon('film')}</span>`)}
            ${raw(message.uploading ? '' : `<span class="message-video-play">${icon('play')}</span>`)}
            <span class="message-video-info">${raw(icon('video'))}${a.duration ? formatDuration(a.duration) : ''}</span>
            ${raw(uploadOverlay(message))}
        </button>
    `;
}

/** Icon and colour group of a document by extension. */
export function fileKind(extension) {
    const groups = {
        sheet: ['xls', 'xlsx', 'ods', 'csv'],
        slides: ['ppt', 'pptx', 'odp'],
        archive: ['zip', 'rar', '7z'],
        audio: ['mp3', 'm4a'],
        pdf: ['pdf'],
        word: ['doc', 'docx', 'odt', 'rtf'],
    };
    const icons = { sheet: 'file-spreadsheet', slides: 'presentation', archive: 'file-archive', audio: 'file-music' };
    const kind = Object.keys(groups).find((key) => groups[key].includes(extension)) ?? 'text';

    return { kind, icon: icons[kind] ?? 'file-text' };
}

function documentAttachment(message) {
    const a = message.attachment;
    const extension = String(a.name || '').split('.').pop().toLowerCase();
    const label = EXTENSION_LABELS[extension] ?? extension.toUpperCase();
    const kind = fileKind(extension);
    const inner = html`
        <span class="message-file-icon" data-ext="${extension}" data-kind="${kind.kind}">${raw(icon(kind.icon))}</span>
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
            ${raw(message.uploading ? '' : html`<button type="button" class="voice-speed" data-voice-speed aria-label="Playback speed">${speedLabel(voiceSpeed())}</button>`)}
            ${raw(uploadOverlay(message))}
        </div>
    `;
}

function messageContent(message) {
    if (message.is_deleted) {
        return `<span class="message-deleted">${icon('ban')}${message.is_mine ? 'You deleted this message' : 'This message was deleted'}</span>`;
    }

    if (message.type === 'call') return callHistory(message);
    if (message.view_once || message.attachment?.view_once) return replyQuote(message) + viewOnceBubble(message);
    if (message.type === 'location') return replyQuote(message) + locationCard(message);
    if (message.type === 'contact') return replyQuote(message) + contactCard(message);
    if (message.type === 'poll') return replyQuote(message) + pollCard(message);

    let attachment = '';
    if (message.attachment) {
        attachment =
            message.type === 'image' ? imageAttachment(message)
            : message.type === 'video' ? videoAttachment(message)
            : message.type === 'sticker' ? stickerAttachment(message)
            : message.type === 'voice' ? voiceAttachment(message)
            : documentAttachment(message);
    }

    const body = message.body ?? '';
    const text = body ? `<div class="message-text${!attachment && isJumboEmoji(body) ? ' is-jumbo' : ''}">${highlightMentions(formatMessageText(body), message.mentions)}</div>` : '';
    const card = message.type === 'text' || !message.type ? linkPreviewCard(message.link_preview) : '';

    const forwarded = message.forwarded
        ? `<span class="message-forwarded">${icon('forward')}${message.forwarded_many ? 'Forwarded many times' : 'Forwarded'}</span>`
        : '';

    return forwarded + statusQuote(message) + replyQuote(message) + attachment + card + text;
}

const escapeText = (value) => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');

/**
 * @mentions (G4): wrap each "@Name" of a mentioned person, outside HTML tags only.
 */
export function highlightMentions(formatted, mentions) {
    if (!Array.isArray(mentions) || !mentions.length) return formatted;
    let result = formatted;
    for (const mention of [...mentions].sort((a, b) => String(b.name).length - String(a.name).length)) {
        const needle = `@${escapeText(mention.name)}`;
        const mine = Number(mention.id) === Number(context.meId);
        const parts = result.split(/(<[^>]*>)/);
        result = parts
            .map((part) => (part.startsWith('<') ? part : part.split(needle).join(`<span class="mention${mine ? ' is-me' : ''}" data-mention-user="${Number(mention.id)}">${needle}</span>`)))
            .join('');
    }
    return result;
}

/**
 * Card with the title, description, site and image of a link (M11).
 * In the composer it is not clickable ({ static: true }).
 */
export function linkPreviewCard(preview, { static: isStatic = false } = {}) {
    const href = typeof preview?.url === 'string' && /^https?:\/\//i.test(preview.url) ? preview.url : null;
    if (!href || (!preview.title && !preview.description)) return '';

    // Only images served by this site (relative path), never a remote address.
    const image = typeof preview.image_url === 'string' && /^\/(?!\/)/.test(preview.image_url) ? preview.image_url : null;
    const width = Number(preview.image_width) || 0;
    const height = Number(preview.image_height) || 0;
    const large = Boolean(image) && !isStatic && width >= 300 && height > 0 && width / height >= 1.3;

    const media = image
        ? html`<span class="link-card-media"${raw(large ? ` style="aspect-ratio: ${width} / ${height}"` : '')}><img src="${image}" alt="" loading="lazy" decoding="async"></span>`
        : '';

    const inner = html`
        ${raw(media)}
        <span class="link-card-body">
            ${raw(preview.title ? html`<span class="link-card-title">${preview.title}</span>` : '')}
            ${raw(preview.description ? html`<span class="link-card-desc">${preview.description}</span>` : '')}
            <span class="link-card-site">${preview.site_name || preview.domain || ''}</span>
        </span>
    `;
    const classes = `link-card${large ? ' is-large' : ''}${image ? '' : ' no-media'}`;

    return isStatic
        ? html`<span class="${classes}">${raw(inner)}</span>`
        : html`<a class="${classes}" href="${href}" target="_blank" rel="noopener noreferrer nofollow ugc">${raw(inner)}</a>`;
}

/** Link preview above the composer: loading row, or the card with a remove button. */
export function composerLinkPreview({ preview = null, loading = false, url = '' }) {
    const content = loading
        ? html`<span class="composer-link-loading"><span class="spinner"></span><span>Fetching preview for ${url}</span></span>`
        : linkPreviewCard(preview, { static: true });

    return html`
        <div class="composer-link${loading ? ' is-loading' : ''}">
            ${raw(content)}
            <button type="button" class="btn-icon btn-icon-sm" data-link-preview-close aria-label="Remove link preview" title="Remove preview">${raw(icon('x'))}</button>
        </div>
    `;
}

export function messageBubble(message) {
    // Notices written by the app (M21) are shown centred, without a bubble.
    if (message.type === 'system') {
        return html`<div class="message is-system" data-message-id="${message.id}" data-sender-id="${message.sender_id}">
            <span class="system-notice">${systemNoticeText(message.system, message.sender_id)}</span>
        </div>`;
    }

    const mediaOnly = !message.is_deleted && ['image', 'video', 'sticker'].includes(message.type) && message.attachment && !message.body && !message.attachment.view_once;
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

    const reactable = isRealId && !message.is_deleted && message.type !== 'call';
    const reactButton = reactable
        ? `<button type="button" class="message-react-btn" data-react-open aria-label="React">${icon('smile-plus')}</button>`
        : '';

    const pill = reactionsPill(message);

    return html`
        <div class="${classes}${pill ? ' has-reactions' : ''}" data-message-id="${message.id}" data-sender-id="${message.sender_id}">
            <div class="message-bubble">
                ${raw(menu)}
                ${raw(message.sender_label ? html`<span class="message-sender" style="--sender-hue: ${Number(message.sender_hue) || 0}">${message.sender_label}</span>` : '')}
                ${raw(messageContent(message))}
                ${raw(messageMeta(message))}
                ${raw(retry)}
                ${raw(pill)}
            </div>
            ${raw(reactButton)}
        </div>
    `;
}

/** Emoji pill under a bubble: the reactions and, when more than one, how many. */
function reactionsPill(message) {
    const reactions = message.is_deleted ? [] : message.reactions ?? [];
    if (!reactions.length) return '';

    const total = reactions.reduce((sum, reaction) => sum + reaction.count, 0);
    const mine = reactions.some((reaction) => reaction.user_ids.some((id) => Number(id) === Number(context.meId)));
    const label = reactions.map((reaction) => `${reaction.emoji} ${reaction.count}`).join(', ');

    return html`
        <button type="button" class="message-reactions${mine ? ' is-mine' : ''}" data-reactions aria-label="Reactions: ${label}">
            ${reactions.map((reaction) => reaction.emoji).join('')}${raw(total > 1 ? html`<span class="message-reactions-count">${total}</span>` : '')}
        </button>
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

const editButton = () =>
    `<button type="button" class="btn-icon btn-icon-sm" data-edit-attachment aria-label="Edit photo" title="Edit (crop, rotate, draw, text)">${icon('pencil')}</button>`;

/** "HD" switch for photos (M16). */
/** "1" switch: send photos/videos as view once (M22). */
const viewOnceToggle = (on) =>
    `<button type="button" class="view-once-toggle${on ? ' is-on' : ''}" data-view-once-toggle aria-pressed="${on}" title="${on ? 'View once: on' : 'Send as view once'}">1</button>`;

const hdToggle = (on) =>
    `<button type="button" class="hd-toggle${on ? ' is-on' : ''}" data-hd-toggle aria-pressed="${on}" title="${on ? 'HD quality: on' : 'Send in HD quality'}">HD</button>`;

export function attachmentPreview({ type, name, size, url, duration = null, loading = false, hd = false, viewOnce = false }) {
    let thumb;
    if (type === 'image' || (type === 'video' && url)) {
        thumb = html`<span class="attachment-preview-media"><img class="attachment-preview-thumb" src="${url}" alt="">${raw(type === 'video' ? `<span class="attachment-preview-play">${icon('play')}</span>` : '')}</span>`;
    } else if (type === 'video') {
        thumb = html`<span class="message-file-icon">${raw(loading ? '<span class="spinner"></span>' : icon('film'))}</span>`;
    } else {
        const kind = fileKind(String(name || '').split('.').pop().toLowerCase());
        thumb = html`<span class="message-file-icon" data-kind="${kind.kind}">${raw(icon(kind.icon))}</span>`;
    }

    const label = { image: 'Photo', video: 'Video' }[type] ?? 'Document';
    const details = [label, duration ? formatDuration(duration) : null, formatBytes(size)].filter(Boolean).join(' · ');

    return html`
        <div class="attachment-preview">
            ${raw(thumb)}
            <span class="composer-context-body">
                <span class="composer-context-title">${name}</span>
                <span class="composer-context-text">${details} — add a caption (optional)</span>
            </span>
            ${raw(viewOnce !== null && ((type === 'image' && !/\.gif$/i.test(name)) || type === 'video') ? viewOnceToggle(viewOnce) : '')}
            ${raw(type === 'image' && !/\.gif$/i.test(name) ? hdToggle(hd) + editButton() : '')}
            <button type="button" class="btn-icon btn-icon-sm" data-remove-attachment aria-label="Remove attachment">${raw(icon('x'))}</button>
        </div>
    `;
}

/**
 * Several picked files (M13): thumbnails to switch between, the selected one's
 * details, and buttons to add more or remove the selected file.
 */
export function attachmentTray({ items, activeIndex = 0, canAdd = true, hd = false, viewOnce = false }) {
    const labels = { image: 'Photo', video: 'Video' };
    const active = items[activeIndex] ?? items[0];
    const activeLabel = labels[active.type] ?? 'Document';

    const thumbs = items
        .map((item, index) => {
            const label = labels[item.type] ?? 'Document';
            let media;
            if (item.url) media = html`<img src="${item.url}" alt="">`;
            else if (item.loading) media = '<span class="spinner"></span>';
            else media = icon(item.type === 'video' ? 'film' : fileKind(String(item.name).split('.').pop().toLowerCase()).icon);

            return html`
                <button type="button" class="attachment-tray-item${index === activeIndex ? ' is-active' : ''}" data-attachment-item="${index}"
                        aria-label="${label} ${index + 1}: ${item.name}" aria-pressed="${index === activeIndex ? 'true' : 'false'}" title="${item.name}">
                    ${raw(media)}
                    ${raw(item.type === 'video' && item.url ? `<span class="attachment-tray-play">${icon('play')}</span>` : '')}
                    ${raw(item.hasCaption ? '<span class="attachment-tray-caption" aria-hidden="true"></span>' : '')}
                </button>
            `;
        })
        .join('');

    return html`
        <div class="attachment-tray">
            <div class="attachment-tray-list">
                ${raw(thumbs)}
                ${raw(canAdd ? `<button type="button" class="attachment-tray-add" data-attachment-add aria-label="Add more files" title="Add more">${icon('plus')}</button>` : '')}
            </div>
            <div class="attachment-tray-footer">
                <span class="composer-context-body">
                    <span class="composer-context-title">${activeLabel} ${activeIndex + 1} of ${items.length} · ${active.name}</span>
                    <span class="composer-context-text">${formatBytes(active.size)} — type a caption for this ${activeLabel.toLowerCase()} (optional)</span>
                </span>
                ${raw(viewOnce !== null && items.some((item) => item.type === 'image' || item.type === 'video') ? viewOnceToggle(viewOnce) : '')}
                ${raw(items.some((item) => item.type === 'image') ? hdToggle(hd) : '')}
                ${raw(active.type === 'image' && !/\.gif$/i.test(active.name) ? editButton() : '')}
                <button type="button" class="btn-icon btn-icon-sm" data-remove-attachment aria-label="Remove this file" title="Remove">${raw(icon('trash-2'))}</button>
            </div>
        </div>
    `;
}

/** Short preview of the message at the top of the mobile action sheet. */
export function messageSheetHeader(message) {
    return html`
        <div class="sheet-handle" aria-hidden="true"></div>
        <div class="sheet-preview">${message.is_deleted ? 'This message was deleted' : previewOf(message) || 'Message'}</div>
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

export function historyStart(type = 'direct') {
    const text = {
        group: 'Messages here go to the people in this group.',
        broadcast: 'Messages sent here reach each person in their own chat with you.',
        channel: "Updates from this channel. Followers can react, but can't reply or see each other.",
        true: 'Messages here go to the people in this group.',
    }[String(type)] ?? 'Messages here go only to you and this person.';
    const symbol = { broadcast: 'megaphone', channel: 'rss' }[String(type)] ?? 'message-circle';
    return `<div class="history-start" data-history-start>${icon(symbol, 'icon-xs')} ${text}</div>`;
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
