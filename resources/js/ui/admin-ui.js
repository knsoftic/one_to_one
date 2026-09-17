/**
 * Admin panel touches: "/" jumps to the people search, tab rows fade where more tabs are hidden
 * (and show the current tab), and App settings highlights the section on screen and says when
 * there are unsaved changes.
 */

const isTyping = (target) => Boolean(target?.closest?.('input, textarea, select, [contenteditable="true"]'));

/** "/" focuses the search in the top bar. */
export function bindSearchShortcut(doc = document) {
    const input = doc.querySelector('[data-admin-search]');
    if (!input) return null;

    const onKey = (event) => {
        if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || isTyping(event.target)) return;
        if (!input.offsetParent) return; // hidden on phones
        event.preventDefault();
        input.focus();
        input.select();
    };
    doc.addEventListener('keydown', onKey);

    return () => doc.removeEventListener('keydown', onKey);
}

/** Classes that fade the edges of a scrolling tab row where tabs are hidden. */
export function updateTabEdges(row) {
    const max = row.scrollWidth - row.clientWidth;
    const flow = getComputedStyle(row).flexDirection;
    const horizontal = !flow.startsWith('column') && max > 2;
    row.classList.toggle('has-more-start', horizontal && row.scrollLeft > 2);
    row.classList.toggle('has-more-end', horizontal && row.scrollLeft < max - 2);
}

export function initTabRows(doc = document, win = window) {
    const rows = [...doc.querySelectorAll('.admin-tabs')];
    rows.forEach((row) => {
        const active = row.querySelector('.admin-tab.is-active');
        if (active && row.scrollWidth > row.clientWidth) {
            row.scrollLeft = Math.max(0, active.offsetLeft - (row.clientWidth - active.offsetWidth) / 2);
        }
        updateTabEdges(row);
        row.addEventListener('scroll', () => updateTabEdges(row), { passive: true });
    });
    const onResize = () => rows.forEach(updateTabEdges);
    win.addEventListener('resize', onResize);

    return rows;
}

/** App settings: the section on screen is marked in the menu; the save bar follows unsaved changes. */
export function initSettingsPage(doc = document, win = window) {
    const nav = doc.querySelector('[data-settings-nav]');
    const form = doc.querySelector('[data-settings-form]');
    if (!nav) return null;

    const links = new Map([...nav.querySelectorAll('a[href^="#"]')].map((link) => [link.hash.slice(1), link]));
    const mark = (id) => {
        links.forEach((link, key) => {
            const active = key === id;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        const current = links.get(id);
        if (current && nav.scrollWidth > nav.clientWidth) {
            nav.scrollTo?.({ left: Math.max(0, current.offsetLeft - 16), behavior: 'smooth' });
        }
    };

    let observer = null;
    const sections = [...links.keys()].map((id) => doc.getElementById(id)).filter(Boolean);
    if (sections.length && typeof win.IntersectionObserver === 'function') {
        const visible = new Map();
        observer = new win.IntersectionObserver((entries) => {
            entries.forEach((entry) => visible.set(entry.target.id, entry.isIntersecting ? entry.boundingClientRect.top : null));
            // The topmost section still on screen.
            const onScreen = [...visible].filter(([, top]) => top !== null).sort((a, b) => a[1] - b[1]);
            if (onScreen.length) mark(onScreen[0][0]);
        }, { rootMargin: '-20% 0px -55% 0px' });
        sections.forEach((section) => observer.observe(section));
    }
    const fromHash = () => links.has(win.location.hash.slice(1)) && mark(win.location.hash.slice(1));
    fromHash();
    win.addEventListener('hashchange', fromHash);

    if (form) {
        const dirty = () => form.classList.add('is-dirty');
        form.addEventListener('input', dirty);
        form.addEventListener('change', dirty);
        form.addEventListener('submit', () => form.classList.remove('is-dirty'));
    }

    return { mark, observer };
}

export function initAdminUi(doc = document, win = window) {
    if (!doc.querySelector('.admin-shell')) return null;

    return {
        search: bindSearchShortcut(doc),
        tabs: initTabRows(doc, win),
        settings: initSettingsPage(doc, win),
    };
}
