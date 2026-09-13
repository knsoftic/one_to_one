/**
 * Date / time formatting in the viewer's locale and timezone.
 */

const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' });
const weekdayLong = new Intl.DateTimeFormat(undefined, { weekday: 'long' });
const weekdayShort = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
const monthDay = new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
const fullDate = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
const shortDate = new Intl.DateTimeFormat(undefined, { day: '2-digit', month: '2-digit', year: '2-digit' });

const toDate = (value) => (value instanceof Date ? value : new Date(value));

const startOfDay = (date) => {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);
    return d;
};

/** Whole calendar days between the date and today (0 = today, 1 = yesterday). */
export function daysAgo(value) {
    const diff = startOfDay(new Date()) - startOfDay(toDate(value));
    return Math.round(diff / 86_400_000);
}

export const dayKey = (value) => {
    const d = toDate(value);
    return `${d.getFullYear()}-${d.getMonth() + 1}-${d.getDate()}`;
};

export const formatTime = (value) => (value ? timeFormat.format(toDate(value)) : '');

/** Label used for date dividers inside a conversation. */
export function formatDayLabel(value) {
    const date = toDate(value);
    const days = daysAgo(date);
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return weekdayLong.format(date);
    return date.getFullYear() === new Date().getFullYear() ? monthDay.format(date) : fullDate.format(date);
}

/** Compact time for the recent chats list. */
export function formatListTime(value) {
    if (!value) return '';
    const date = toDate(value);
    const days = daysAgo(date);
    if (days === 0) return formatTime(date);
    if (days === 1) return 'Yesterday';
    if (days < 7) return weekdayShort.format(date);
    return shortDate.format(date);
}

/** "last seen today at 10:35 PM" style presence text. */
export function formatLastSeen(value) {
    if (!value) return 'offline';
    const date = toDate(value);
    const seconds = (Date.now() - date.getTime()) / 1000;
    if (seconds < 60) return 'last seen just now';

    const days = daysAgo(date);
    const time = formatTime(date);
    if (days === 0) return `last seen today at ${time}`;
    if (days === 1) return `last seen yesterday at ${time}`;
    if (days < 7) return `last seen ${weekdayLong.format(date)} at ${time}`;
    return `last seen ${fullDate.format(date)} at ${time}`;
}
