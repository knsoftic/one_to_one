import { describe, expect, it } from 'vitest';
import { formatMessageText, stripFormatting } from '../formatting';

describe('formatMessageText', () => {
    it('formats bold, italic, strikethrough and inline code', () => {
        expect(formatMessageText('*bold* _italic_ ~gone~ `code`')).toBe(
            '<strong>bold</strong> <em>italic</em> <s>gone</s> <code class="msg-code">code</code>',
        );
    });

    it('needs markers at word boundaries', () => {
        expect(formatMessageText('snake_case_name and 2*3*4')).toBe('snake_case_name and 2*3*4');
        expect(formatMessageText('a * not bold*')).toBe('a * not bold*');
        expect(formatMessageText('Hello *world*!')).toBe('Hello <strong>world</strong>!');
        expect(formatMessageText('(*nested _italic_*)')).toBe('(<strong>nested <em>italic</em></strong>)');
    });

    it('escapes HTML everywhere, including inside formatting and code', () => {
        expect(formatMessageText('<img src=x onerror=alert(1)>')).toBe('&lt;img src=x onerror=alert(1)&gt;');
        expect(formatMessageText('*<b>x</b>*')).toBe('<strong>&lt;b&gt;x&lt;/b&gt;</strong>');
        expect(formatMessageText('`<script>`')).toBe('<code class="msg-code">&lt;script&gt;</code>');
        expect(formatMessageText('```<a href="javascript:x">*no*</a>```')).toBe(
            '<code class="msg-mono">&lt;a href=&quot;javascript:x&quot;&gt;*no*&lt;/a&gt;</code>',
        );
    });

    it('links URLs and never formats inside them', () => {
        expect(formatMessageText('see https://example.com/a_b_c?x=1&y=*2*')).toBe(
            'see <a href="https://example.com/a_b_c?x=1&amp;y=*2*" target="_blank" rel="noopener noreferrer nofollow ugc">https://example.com/a_b_c?x=1&amp;y=*2*</a>',
        );
        expect(formatMessageText('javascript:alert(1)')).toBe('javascript:alert(1)');
    });

    it('renders lists and quotes and keeps other line breaks', () => {
        expect(formatMessageText('Shopping:\n* milk\n- eggs\nDone')).toBe(
            'Shopping:<ul class="msg-ul"><li>milk</li><li>eggs</li></ul>Done',
        );
        expect(formatMessageText('1. one\n2. *two*')).toBe('<ol class="msg-ol"><li>one</li><li><strong>two</strong></li></ol>');
        expect(formatMessageText('> quoted\n> text\nreply')).toBe('<blockquote class="msg-blockquote">quoted\ntext</blockquote>reply');
        expect(formatMessageText('line 1\nline 2')).toBe('line 1\nline 2');
    });

    it('strips markers for previews', () => {
        expect(stripFormatting('*Hi* _there_ ```code```')).toBe('Hi there code');
    });
});
