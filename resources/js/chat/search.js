import { debounce, errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { formatListTime } from './format';

/**
 * Wrap every case-insensitive occurrence of `term` inside `root`'s text in <mark>.
 * Works on text nodes only, so links and formatting stay intact.
 *
 * @returns {number} how many matches were marked
 */
export function highlightText(root, term) {
    const needle = term.trim().toLowerCase();
    if (!root || needle.length < 2) return 0;

    const walker = root.ownerDocument.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);

    let count = 0;
    for (const node of nodes) {
        const text = node.nodeValue;
        const lower = text.toLowerCase();
        let index = lower.indexOf(needle);
        if (index === -1) continue;

        const fragment = root.ownerDocument.createDocumentFragment();
        let last = 0;
        while (index !== -1) {
            fragment.append(text.slice(last, index));
            const mark = root.ownerDocument.createElement('mark');
            mark.className = 'search-hit';
            mark.textContent = text.slice(index, index + needle.length);
            fragment.append(mark);
            count++;
            last = index + needle.length;
            index = lower.indexOf(needle, last);
        }
        fragment.append(text.slice(last));
        node.replaceWith(fragment);
    }

    return count;
}

export function clearHighlights(root) {
    root?.querySelectorAll('mark.search-hit').forEach((mark) => {
        const parent = mark.parentNode;
        mark.replaceWith(mark.textContent);
        parent?.normalize();
    });
}

/**
 * Search inside the open chat: results list, older / newer navigation,
 * jump to the message and highlight the words.
 */
export class ChatSearch {
    constructor(chat) {
        this.chat = chat;
        this.results = [];
        this.index = -1;
        this.term = '';
        this.abort = null;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            bar: q('[data-chat-search]'),
            input: q('[data-chat-search-input]'),
            count: q('[data-chat-search-count]'),
            older: q('[data-chat-search-older]'),
            newer: q('[data-chat-search-newer]'),
            close: q('[data-chat-search-close]'),
            list: q('[data-chat-search-results]'),
        };

        if (!this.el.bar || !chat.api.has('messagesSearch')) return;

        this.run = debounce(() => this.search(), 300);
        this.bind();
    }

    get isOpen() {
        return Boolean(this.el.bar && !this.el.bar.hidden);
    }

    bind() {
        const { input, older, newer, close, list } = this.el;

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'chat-search') this.open();
        });

        document.addEventListener('chat:menu', (event) => {
            event.detail.items.unshift(
                html`<button type="button" class="dropdown-item" data-action="chat-search" role="menuitem">${raw(icon('search'))} Search</button>`,
            );
        });

        input.addEventListener('input', () => this.run());
        input.addEventListener('focus', () => {
            if (this.results.length) this.showList(true);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.results.length ? this.go(this.index + 1 < this.results.length ? this.index + 1 : 0) : this.search();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
            } else if (event.key === 'ArrowDown' && this.results.length) {
                event.preventDefault();
                this.showList(true);
                list.querySelector('[data-search-result]')?.focus();
            }
        });

        older.addEventListener('click', () => this.go(this.index + 1));
        newer.addEventListener('click', () => this.go(this.index - 1));
        close.addEventListener('click', () => this.close());

        list.addEventListener('click', (event) => {
            const item = event.target.closest('[data-search-result]');
            if (item) this.go(Number(item.dataset.searchResult));
        });
        list.addEventListener('keydown', (event) => {
            const items = [...list.querySelectorAll('[data-search-result]')];
            const current = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[Math.min(items.length - 1, current + 1)]?.focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                current <= 0 ? input.focus() : items[current - 1].focus();
            } else if (event.key === 'Escape') {
                this.showList(false);
                input.focus();
            }
        });

        document.addEventListener('click', (event) => {
            if (this.isOpen && !this.el.bar.contains(event.target)) this.showList(false);
        });

        document.addEventListener('chat:opened', () => this.close());
        document.addEventListener('chat:closed', () => this.close());

        document.addEventListener('app:back', (event) => {
            if (this.isOpen) {
                event.preventDefault();
                this.close();
            }
        });
    }

    open() {
        if (!this.chat.active) return;
        this.el.bar.hidden = false;
        this.chat.el.panel.classList.add('is-searching');
        this.el.input.focus();
        this.el.input.select();
    }

    close() {
        if (!this.el.bar) return;
        this.abort?.abort();
        clearHighlights(this.chat.el.messageList);
        this.el.bar.hidden = true;
        this.chat.el.panel.classList.remove('is-searching');
        this.el.input.value = '';
        this.results = [];
        this.index = -1;
        this.term = '';
        this.render();
    }

    async search() {
        const term = this.el.input.value.trim();
        const conversationId = this.chat.active?.id;
        this.abort?.abort();

        if (term.length < 2 || !conversationId) {
            this.results = [];
            this.index = -1;
            this.term = '';
            clearHighlights(this.chat.el.messageList);
            this.render();
            return;
        }

        this.abort = new AbortController();
        this.el.count.textContent = 'Searching…';

        try {
            const { data } = await this.chat.api.searchMessages(conversationId, term, this.abort.signal);
            if (this.chat.active?.id !== conversationId) return;
            this.term = term;
            this.results = data;
            this.index = -1;
            this.render();
            this.showList(true);
        } catch (error) {
            if (error?.code === 'ERR_CANCELED' || error?.name === 'CanceledError') return;
            this.el.count.textContent = errorMessage(error, 'Search failed');
        }
    }

    render() {
        const { count, older, newer, list } = this.el;
        const total = this.results.length;

        if (!this.term) {
            count.textContent = '';
        } else if (!total) {
            count.textContent = 'No results';
        } else {
            count.textContent = this.index === -1 ? `${total}${total === 50 ? '+' : ''} found` : `${this.index + 1} of ${total}`;
        }

        older.disabled = !total || this.index >= total - 1;
        newer.disabled = !total || this.index <= 0;

        list.innerHTML = this.results
            .map(
                (result, i) => html`
                    <button type="button" class="dropdown-item chat-search-result${i === this.index ? ' is-current' : ''}" data-search-result="${i}" role="option" aria-selected="${i === this.index ? 'true' : 'false'}">
                        <span class="chat-search-result-text">${result.is_mine ? 'You: ' : ''}${result.preview}</span>
                        <time class="chat-search-result-time" datetime="${result.created_at}">${formatListTime(result.created_at)}</time>
                    </button>
                `,
            )
            .join('');

        if (!total) this.showList(false);
    }

    showList(show) {
        this.el.list.hidden = !show || this.results.length === 0;
    }

    async go(index) {
        if (index < 0 || index >= this.results.length) return;
        this.index = index;
        this.render();
        this.showList(false);

        clearHighlights(this.chat.el.messageList);
        const el = await this.chat.jumpToMessage(this.results[index].id, { deep: true });
        if (el && this.term) {
            el.querySelectorAll('.message-text, .message-file-name').forEach((part) => highlightText(part, this.term));
        }
    }
}
