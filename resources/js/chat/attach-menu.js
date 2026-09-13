import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';

/**
 * The paperclip menu (like WhatsApp's attach sheet): files, location and
 * more. Features register their own entries.
 */
export class AttachMenu {
    constructor(chat, button) {
        this.chat = chat;
        this.button = button;
        this.items = [];
        this.menu = null;
        this.onDocumentClick = (event) => {
            if (!this.menu?.contains(event.target) && !this.button.contains(event.target)) this.close();
        };
        this.onKey = (event) => {
            if (event.key === 'Escape') this.close();
        };

        button.setAttribute('aria-haspopup', 'menu');
        button.setAttribute('aria-expanded', 'false');
    }

    /**
     * @param {{id: string, icon: string, label: string, tone?: string, run: () => void}} item
     */
    add(item) {
        this.items.push(item);
    }

    toggle() {
        this.menu ? this.close() : this.open();
    }

    open() {
        if (!this.chat.active || this.chat.el.composer.classList.contains('is-disabled')) return;

        this.menu = document.createElement('div');
        this.menu.className = 'dropdown-menu attach-menu';
        this.menu.setAttribute('role', 'menu');
        this.menu.innerHTML = this.items
            .map((item) => html`
                <button type="button" class="dropdown-item attach-item" role="menuitem" data-attach-item="${item.id}">
                    <span class="attach-item-icon" data-tone="${item.tone ?? item.id}">${raw(icon(item.icon))}</span>${item.label}
                </button>`)
            .join('');

        this.menu.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-attach-item]');
            if (!choice) return;
            const item = this.items.find((entry) => entry.id === choice.dataset.attachItem);
            this.close();
            item?.run();
        });

        this.chat.el.composer.appendChild(this.menu);
        this.button.setAttribute('aria-expanded', 'true');
        this.menu.querySelector('.dropdown-item')?.focus();
        setTimeout(() => document.addEventListener('click', this.onDocumentClick), 0);
        document.addEventListener('keydown', this.onKey);
    }

    close() {
        if (!this.menu) return;
        this.menu.remove();
        this.menu = null;
        this.button.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKey);
    }
}
