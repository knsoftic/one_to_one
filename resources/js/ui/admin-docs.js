import { icon } from '../lib/icons';

/**
 * Admin → Guides: a Copy button on every command block, and the section you are
 * reading highlighted in "On this page".
 */
export function initAdminDocs(root = document.querySelector('[data-doc]')) {
    if (!root) return null;

    root.querySelectorAll('.doc-prose pre').forEach((block) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'doc-copy';
        button.innerHTML = `${icon('copy')}<span>Copy</span>`;
        button.addEventListener('click', async () => {
            const text = block.querySelector('code')?.textContent ?? block.textContent;
            try {
                await navigator.clipboard.writeText(text.replace(/\n$/, ''));
                button.innerHTML = `${icon('check')}<span>Copied</span>`;
            } catch {
                button.innerHTML = `${icon('circle-alert')}<span>Select and copy</span>`;
            }
            setTimeout(() => {
                button.innerHTML = `${icon('copy')}<span>Copy</span>`;
            }, 1800);
        });
        block.classList.add('has-copy');
        block.appendChild(button);
    });

    // On phones the contents start closed so the guide is visible first.
    const toc = root.querySelector('[data-doc-toc]');
    if (toc && window.matchMedia?.('(max-width: 1023px)').matches) toc.open = false;
    toc?.addEventListener('click', (event) => {
        if (event.target.closest('a') && window.matchMedia?.('(max-width: 1023px)').matches) toc.open = false;
    });

    const links = new Map([...root.querySelectorAll('[data-doc-link]')].map((link) => [link.dataset.docLink, link]));
    const headings = [...links.keys()].map((id) => document.getElementById(id)).filter(Boolean);
    if (!headings.length || typeof IntersectionObserver !== 'function') return { headings };

    const mark = (id) => links.forEach((link, key) => link.classList.toggle('is-active', key === id));
    const observer = new IntersectionObserver((entries) => {
        const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
        if (visible.length) mark(visible[0].target.id);
    }, { rootMargin: '0px 0px -70% 0px' });
    headings.forEach((heading) => observer.observe(heading));

    return { headings, observer };
}
