import { escapeHtml } from '../lib/dom';

/**
 * WhatsApp-style message formatting, rendered safely:
 *
 *   *bold*   _italic_   ~strikethrough~   `inline code`   ```monospace block```
 *   * item / - item   → bulleted list
 *   1. item           → numbered list
 *   > quote           → quote
 *
 * Everything is HTML-escaped first; only the tags below are ever produced.
 * URLs become links and are never formatted inside.
 */

const URL_PATTERN = /\bhttps?:\/\/[^\s<>"'`]+[^\s<>"'`.,:;!?)\]]/gi;

// A marker opens after the start of text, whitespace or punctuation, and closes before them.
const BEFORE = String.raw`(^|[\s([{"'.,!?:;>\-]|&quot;|&#39;)`;
const AFTER = String.raw`(?=$|[\s)\]}"'.,!?:;<\-]|&quot;|&#39;|&amp;)`;

const INLINE_RULES = [
    { marker: '\\*', tag: 'strong' },
    { marker: '_', tag: 'em' },
    { marker: '~', tag: 's' },
].map(({ marker, tag }) => ({
    tag,
    pattern: new RegExp(`${BEFORE}${marker}(?=\\S)((?:(?!${marker}).)*?\\S)${marker}${AFTER}`, 'g'),
}));

/** Placeholder character: control characters are stripped from messages by the server. */
const SLOT = '\u0000';

export function formatMessageText(text) {
    const source = String(text ?? '');
    if (!source) return '';

    // 1. Monospace blocks are taken out first: nothing inside them is formatted.
    const parts = source.split(/```([\s\S]+?)```/);

    return parts
        .map((part, index) => (index % 2 === 1 ? `<code class="msg-mono">${escapeHtml(part)}</code>` : formatBlocks(part)))
        .join('');
}

/** Lists and quotes, line by line; plain lines keep their line breaks (the bubble uses pre-wrap). */
function formatBlocks(text) {
    const lines = text.split('\n');
    const out = [];
    let block = null; // { tag, items: [] }

    const flush = () => {
        if (!block) return;
        const inner = block.items.map((item) => (block.tag === 'blockquote' ? item : `<li>${item}</li>`)).join(block.tag === 'blockquote' ? '\n' : '');
        out.push({ html: `<${block.tag} class="msg-${block.tag}">${inner}</${block.tag}>`, block: true });
        block = null;
    };

    for (const line of lines) {
        const bullet = line.match(/^[*-] (.+)$/);
        const numbered = line.match(/^\d{1,3}\. (.+)$/);
        const quote = line.match(/^> ?(.*)$/);

        const kind = bullet ? 'ul' : numbered ? 'ol' : quote ? 'blockquote' : null;
        const content = bullet?.[1] ?? numbered?.[1] ?? quote?.[1];

        if (kind) {
            if (block?.tag !== kind) flush();
            block ??= { tag: kind, items: [] };
            block.items.push(formatInline(content));
            continue;
        }

        flush();
        out.push({ html: formatInline(line), block: false });
    }
    flush();

    // Join with newlines, except around block elements (they break lines themselves).
    return out.reduce((html, piece, i) => {
        const previous = out[i - 1];
        const separator = i === 0 || piece.block || previous.block ? '' : '\n';
        return html + separator + piece.html;
    }, '');
}

function formatInline(text) {
    const slots = [];
    const keep = (html) => `${SLOT}${slots.push(html) - 1}${SLOT}`;

    // Protect inline code and links before escaping and formatting.
    let result = text
        .replace(/`([^`\n]+)`/g, (_, code) => keep(`<code class="msg-code">${escapeHtml(code)}</code>`))
        .replace(URL_PATTERN, (url) => {
            const safe = escapeHtml(url);
            return keep(`<a href="${safe}" target="_blank" rel="noopener noreferrer nofollow ugc">${safe}</a>`);
        });

    result = escapeHtml(result);

    for (const { pattern, tag } of INLINE_RULES) {
        // Repeat so neighbours such as "*a* *b*" (sharing a space) are both formatted.
        let previous;
        do {
            previous = result;
            result = result.replace(pattern, (_, before, inner) => `${before}<${tag}>${inner}</${tag}>`);
        } while (result !== previous);
    }

    return result.replace(new RegExp(`${SLOT}(\\d+)${SLOT}`, 'g'), (_, index) => slots[Number(index)]);
}

/** Plain text without formatting markers (for previews and notifications). */
export function stripFormatting(text) {
    return String(text ?? '')
        .replace(/```([\s\S]+?)```/g, '$1')
        .replace(/`([^`\n]+)`/g, '$1')
        .replace(/(^|\s)[*_~](\S(?:.*?\S)?)[*_~](?=\s|$)/g, '$1$2');
}
