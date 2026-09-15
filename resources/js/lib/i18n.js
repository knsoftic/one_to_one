/**
 * X1 — Urdu. Pages and chat screens are written in English; in Urdu the browser swaps
 * each piece of interface text for its translation from lang/ur.json, also for text
 * the app adds later (chats, dialogs, toasts). People's own words — messages, names,
 * About, captions — are marked translate="no" and never touched.
 */

/** People's own words: messages, names, captions, polls, link cards, quick replies. */
const USER_CONTENT = [
    '.message-text', '.message-file-name', '.status-caption', '.poll-question', '.poll-option-text',
    '.link-card-title', '.link-card-desc', '.link-card-site', '.location-title', '.quick-reply-text', '.quick-reply-option-text',
    '.conversation-name', '.conversation-intro-name', '.chat-header-name', '.group-info-name', '.group-member-name', '.group-tile-name',
    '.status-row-name', '.status-viewer-name', '.reaction-row-name', '.receipt-name', '.community-card-name', '.channel-row-name',
    '.lightbox-name', '.gallery-chat-name', '.forward-item-name', '.contact-card-name', '.calls-entry-name', '.call-name',
    '.mention-option-name', '.wa-me-name', '.wa-me-about',
].join(', ');

/** Elements whose text is never translated. */
const SKIP = `script, style, textarea, code, pre, svg, [translate="no"], [contenteditable=""], [contenteditable="true"], ${USER_CONTENT}`;

/** Attributes with interface text. */
export const ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'data-confirm', 'data-confirm-title', 'data-confirm-label'];

const VALUE = '((?:[^.!?—\\n]|(?<=\\d)\\.(?=\\d)){0,120}?)';

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const squash = (text) => text.replace(/\s+/g, ' ').trim();

/**
 * @param {Record<string, string>} dictionary English => Urdu; "{0}", "{1}" mark values.
 */
export function createTranslator(dictionary) {
    const exact = new Map();
    const patterns = [];

    for (const [english, translated] of Object.entries(dictionary ?? {})) {
        if (typeof translated !== 'string' || !translated || translated === english) continue;
        if (!/\{\d+\}/.test(english)) {
            exact.set(english, translated);
            continue;
        }
        const pieces = english.split(/\{(\d+)\}/);
        const literal = pieces.filter((_, i) => i % 2 === 0).join('');
        // Too little fixed text ("{0} {1}") would match anything.
        if (literal.replace(/[^\p{L}]/gu, '').length < 3) continue;
        const order = pieces.filter((_, i) => i % 2 === 1).map(Number);
        // A value (name, number, size) never spans a sentence end, so "{0} added {1}" can't
        // swallow a whole paragraph that happens to contain "added". "2.4 MB" is fine.
        const source = pieces.map((piece, i) => (i % 2 === 0 ? escapeRegExp(piece) : VALUE)).join('');
        const longest = pieces.filter((_, i) => i % 2 === 0).sort((a, b) => b.length - a.length)[0];
        patterns.push({ regex: new RegExp(`^${source}$`, 'u'), order, translated, longest, weight: literal.length });
    }
    // More specific (longer fixed text) first.
    patterns.sort((a, b) => b.weight - a.weight);

    /** A whole string, or null when there is no translation. */
    function lookup(text) {
        const key = squash(text);
        if (!key) return null;
        const direct = exact.get(key);
        if (direct !== undefined) return direct;

        for (const pattern of patterns) {
            if (pattern.longest && !key.includes(pattern.longest)) continue;
            const match = key.match(pattern.regex);
            if (!match) continue;
            const values = {};
            pattern.order.forEach((index, i) => {
                const value = match[i + 1];
                values[index] = exact.get(value) ?? value;
            });
            return pattern.translated.replace(/\{(\d+)\}/g, (_, index) => values[index] ?? '');
        }
        return null;
    }

    /** Keeps the spaces around the text (they matter between inline elements). */
    function translate(text) {
        if (typeof text !== 'string' || !/[A-Za-z]/.test(text)) return text;
        const result = lookup(text);
        if (result === null) return text;
        const lead = text.match(/^\s*/)[0] ? ' ' : '';
        const trail = text.match(/\s*$/)[0] ? ' ' : '';
        return lead + result + trail;
    }

    return { translate, lookup, size: exact.size + patterns.length };
}

function skipped(element) {
    return Boolean(element?.closest?.(SKIP));
}

/** Translate the text and interface attributes inside a node. */
export function translateTree(node, translator) {
    if (!node) return;
    if (node.nodeType === Node.TEXT_NODE) {
        translateTextNode(node, translator);
        return;
    }
    if (node.nodeType !== Node.ELEMENT_NODE && node.nodeType !== Node.DOCUMENT_NODE && node.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) return;
    if (node.nodeType === Node.ELEMENT_NODE && skipped(node)) return;

    const root = node.nodeType === Node.DOCUMENT_NODE ? node.body ?? node.documentElement : node;
    if (!root) return;
    if (root.nodeType === Node.ELEMENT_NODE) translateAttributes(root, translator);

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
        acceptNode(current) {
            if (current.nodeType === Node.ELEMENT_NODE) {
                return current.matches(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
            return NodeFilter.FILTER_ACCEPT;
        },
    });
    let current = walker.nextNode();
    while (current) {
        if (current.nodeType === Node.TEXT_NODE) translateTextNode(current, translator);
        else translateAttributes(current, translator);
        current = walker.nextNode();
    }
}

function translateTextNode(node, translator) {
    const text = node.nodeValue;
    if (!text || !/[A-Za-z]/.test(text) || skipped(node.parentElement)) return;
    const next = translator.translate(text);
    if (next !== text) node.nodeValue = next;
}

function translateAttributes(element, translator) {
    for (const name of ATTRIBUTES) {
        const value = element.getAttribute(name);
        if (!value) continue;
        const next = translator.translate(value);
        if (next !== value) element.setAttribute(name, next.trim());
    }
    // Buttons made from <input type="submit" value="…">.
    if (element.tagName === 'INPUT' && ['submit', 'button', 'reset'].includes(element.type) && element.value) {
        const next = translator.translate(element.value);
        if (next !== element.value) element.value = next.trim();
    }
}

/** Keep translating what the app adds or changes later. */
export function watchTranslations(root, translator) {
    const observer = new MutationObserver((records) => {
        for (const record of records) {
            if (record.type === 'childList') {
                record.addedNodes.forEach((added) => translateTree(added, translator));
            } else if (record.type === 'characterData') {
                translateTextNode(record.target, translator);
            } else if (record.type === 'attributes' && record.target.nodeType === Node.ELEMENT_NODE && !skipped(record.target)) {
                translateAttributes(record.target, translator);
            }
        }
    });
    observer.observe(root, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ATTRIBUTES });
    return observer;
}

/** "Settings · One2One Chat": the page name before the dot is translated. */
export function translateTitle(translator, doc = document) {
    const [name, ...rest] = doc.title.split(' · ');
    const translated = translator.lookup(name);
    if (translated) doc.title = [translated, ...rest].join(' · ');
}

let active = null;

/** The running translator (null in English). */
export function currentTranslator() {
    return active;
}

/** Translate a string in code that doesn't go through the page (e.g. system notifications). */
export function t(text) {
    return active ? active.translate(text) : text;
}

/**
 * Start translating when the page is not in English.
 *
 * @param {() => Promise<Record<string, string>>} loadDictionary
 */
export async function initI18n(loadDictionary, doc = document) {
    const root = doc.documentElement;
    const reveal = () => root.removeAttribute('data-i18n-pending');
    const locale = (root.getAttribute('lang') || 'en').toLowerCase();
    if (locale.startsWith('en')) {
        reveal();
        return null;
    }

    try {
        const translator = createTranslator(await loadDictionary(locale));
        active = translator;
        translateTree(doc, translator);
        translateTitle(translator, doc);
        watchTranslations(doc.body, translator);
        return translator;
    } catch (error) {
        console.error('Translations could not be loaded.', error);
        return null;
    } finally {
        reveal();
    }
}
