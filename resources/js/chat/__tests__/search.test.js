// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { clearHighlights, highlightText } from '../search';

describe('highlightText', () => {
    it('marks every case-insensitive match without touching links', () => {
        const root = document.createElement('div');
        root.innerHTML = 'Invoice sent. <a href="https://example.com/invoice">see invoice</a>';

        expect(highlightText(root, 'INVOICE')).toBe(2);
        expect(root.querySelectorAll('mark.search-hit')).toHaveLength(2);
        expect(root.querySelector('a').getAttribute('href')).toBe('https://example.com/invoice');
        expect(root.textContent).toBe('Invoice sent. see invoice');
    });

    it('does not interpret the term as HTML and ignores too short terms', () => {
        const root = document.createElement('div');
        root.textContent = 'a <b> tag';

        expect(highlightText(root, '<b>')).toBe(1);
        expect(root.querySelector('b')).toBeNull();
        expect(highlightText(root, 'a')).toBe(0);
    });

    it('restores the original text', () => {
        const root = document.createElement('div');
        root.innerHTML = '<p>Hello hello world</p>';
        highlightText(root, 'hello');
        clearHighlights(root);

        expect(root.innerHTML).toBe('<p>Hello hello world</p>');
        expect(root.querySelector('p').childNodes).toHaveLength(1);
    });
});
