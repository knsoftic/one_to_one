// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { firstUrl } from '../link-preview';
import { linkPreviewCard } from '../templates';

const preview = {
    url: 'https://news.example.com/article',
    title: 'Rain <b>expected</b>',
    description: 'Forecast "today"',
    site_name: 'Example News',
    domain: 'news.example.com',
    image_url: '/link-previews/4/image',
    image_width: 480,
    image_height: 270,
};

function render(markup) {
    const host = document.createElement('div');
    host.innerHTML = markup;
    return host;
}

describe('firstUrl', () => {
    it('finds the first link without trailing punctuation', () => {
        expect(firstUrl('See https://a.example/x?y=1, and http://b.example')).toBe('https://a.example/x?y=1');
        expect(firstUrl('(https://a.example/path).')).toBe('https://a.example/path');
        expect(firstUrl('no links here')).toBeNull();
    });

    it('ignores links inside code', () => {
        expect(firstUrl('run `curl https://a.example` first')).toBeNull();
        expect(firstUrl('```\nhttps://a.example\n``` then https://b.example')).toBe('https://b.example');
    });
});

describe('linkPreviewCard', () => {
    it('renders an escaped, clickable card with a large image', () => {
        const card = render(linkPreviewCard(preview)).querySelector('a.link-card');

        expect(card.getAttribute('href')).toBe(preview.url);
        expect(card.getAttribute('rel')).toContain('noopener');
        expect(card.classList.contains('is-large')).toBe(true);
        expect(card.querySelector('.link-card-title').textContent).toBe('Rain <b>expected</b>');
        expect(card.querySelector('b')).toBeNull();
        expect(card.querySelector('img').getAttribute('src')).toBe('/link-previews/4/image');
    });

    it('refuses unsafe links and remote images', () => {
        expect(linkPreviewCard({ ...preview, url: 'javascript:alert(1)' })).toBe('');
        expect(linkPreviewCard({ ...preview, title: null, description: null })).toBe('');

        const card = render(linkPreviewCard({ ...preview, image_url: '//evil.example/pixel.png' }));
        expect(card.querySelector('img')).toBeNull();
        expect(card.querySelector('.link-card').classList.contains('no-media')).toBe(true);
    });

    it('is not a link in the composer', () => {
        const host = render(linkPreviewCard(preview, { static: true }));
        expect(host.querySelector('a')).toBeNull();
        expect(host.querySelector('.link-card.is-large')).toBeNull();
    });
});
