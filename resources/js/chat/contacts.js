import axios from '../bootstrap';
import { debounce, errorMessage, html } from '../lib/dom';
import { isNativeApp } from '../lib/native';
import { toast } from '../lib/toast';
import * as T from './templates';

const SYNC_BATCH = 3000;

// The mobile app re-checks the phone book in the background at most this often.
const AUTO_SYNC_INTERVAL_MS = 12 * 60 * 60 * 1000;

/**
 * Parse a vCard (.vcf) export into phone-book entries.
 *
 * @returns {{name: string, phones: string[]}[]}
 */
export function parseVcf(text) {
    // Unfold folded lines (continuation lines start with a space or tab).
    const lines = String(text).replace(/\r\n|\r/g, '\n').replace(/\n[ \t]/g, '').split('\n');
    const entries = [];
    let current = null;

    for (const rawLine of lines) {
        const line = rawLine.trim();
        const upper = line.toUpperCase();

        if (upper === 'BEGIN:VCARD') {
            current = { name: '', fallbackName: '', phones: [] };
        } else if (upper === 'END:VCARD' && current) {
            const name = current.name || current.fallbackName;
            if (current.phones.length) entries.push({ name, phones: current.phones.slice(0, 5) });
            current = null;
        } else if (current) {
            const separator = line.indexOf(':');
            if (separator === -1) continue;
            const key = line.slice(0, separator).toUpperCase().replace(/^ITEM\d+\./, '');
            const value = line.slice(separator + 1).trim();

            if (key === 'FN' || key.startsWith('FN;')) {
                current.name = value;
            } else if ((key === 'N' || key.startsWith('N;')) && !current.fallbackName) {
                current.fallbackName = value.split(';').filter(Boolean).reverse().join(' ').trim();
            } else if ((key === 'TEL' || key.startsWith('TEL;')) && value) {
                current.phones.push(value.replace(/^tel:/i, ''));
            }
        }
    }

    return entries;
}

/**
 * "New chat" panel: contacts saved in the phone who are registered on the
 * app (like WhatsApp), plus search for anyone else.
 */
export class ContactsPanel {
    constructor(chat) {
        this.chat = chat;
        this.contacts = [];
        this.loaded = false;
        this.searchAbort = null;

        const q = (selector) => document.querySelector(selector);
        this.el = {
            sidebar: q('[data-sidebar]'),
            chatsView: q('[data-sidebar-view="chats"]'),
            view: q('[data-sidebar-view="contacts"]'),
            search: q('[data-contacts-search]'),
            list: q('[data-contacts-list]'),
            results: q('[data-contacts-results]'),
            count: q('[data-contacts-count]'),
            sync: q('[data-contacts-sync]'),
            importInput: q('[data-contacts-import]'),
        };

        // The mobile app reads the whole phone book; Android Chrome has the Contact Picker API.
        this.native = isNativeApp();
        this.pickerSupported = this.native || ('contacts' in navigator && typeof navigator.contacts?.select === 'function');
        this.el.sync.hidden = !this.pickerSupported;

        this.bind();
        this.load();
        if (this.native) this.autoSync();
    }

    get isOpen() {
        return this.el.sidebar.dataset.mode === 'contacts';
    }

    bind() {
        const runSearch = debounce((term) => this.searchPeople(term), 300);

        this.el.search.addEventListener('input', () => {
            const term = this.el.search.value.trim();
            this.render();
            if (term.length >= 2) {
                runSearch(term);
            } else {
                runSearch.cancel();
                this.el.results.hidden = true;
                this.el.results.innerHTML = '';
            }
        });

        this.el.search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.close();
        });

        this.el.view.addEventListener('click', (event) => {
            const start = event.target.closest('[data-start-user-id]');
            if (start) {
                this.close();
                this.chat.startConversationWith(Number(start.dataset.startUserId));
            }
        });

        this.el.sync.addEventListener('click', () => this.syncFromPhone());

        this.el.importInput.addEventListener('change', async () => {
            const file = this.el.importInput.files?.[0];
            this.el.importInput.value = '';
            if (!file) return;
            if (file.size > 5 * 1024 * 1024) {
                toast.error('This contacts file is too large (max 5 MB).');
                return;
            }
            const entries = parseVcf(await file.text());
            if (!entries.length) {
                toast.error('No phone numbers were found in this file. Export your contacts as a .vcf (vCard) file.');
                return;
            }
            this.upload(entries);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'close-contacts') this.close();
        });

        document.addEventListener('chat:opened', () => {
            if (this.isOpen) this.close();
        });
    }

    open() {
        this.el.sidebar.dataset.mode = 'contacts';
        this.el.chatsView.hidden = true;
        this.el.view.hidden = false;
        this.chat.showListView();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'contacts' } }));

        if (!this.loaded) this.load();
        if (!window.matchMedia('(pointer: coarse)').matches) this.el.search.focus();

        // Mobile app, first "New chat": ask for the phone book right away, like WhatsApp.
        if (this.native && !this.askedThisSession && !this.lastSyncAt()) {
            this.askedThisSession = true;
            this.syncFromNativePhoneBook({ quietDenied: true });
        }
    }

    close() {
        this.el.sidebar.dataset.mode = 'chats';
        this.el.view.hidden = true;
        this.el.chatsView.hidden = false;
        this.el.search.value = '';
        this.el.results.hidden = true;
        this.el.results.innerHTML = '';
        this.render();
        document.dispatchEvent(new CustomEvent('chat:sidebar-mode', { detail: { mode: 'chats' } }));
    }

    async load() {
        if (!this.chat.api.has('contacts')) return;
        try {
            const { data } = await axios.get(this.chat.api.url('contacts'));
            this.setContacts(data.data);
        } catch (error) {
            this.el.list.innerHTML = T.emptyState({ iconName: 'wifi-off', title: "Couldn't load contacts", text: errorMessage(error) });
        }
    }

    setContacts(contacts) {
        this.contacts = contacts;
        this.loaded = true;
        this.chat.applyContacts(contacts);
        this.render();
    }

    render() {
        const term = this.el.search.value.trim().toLowerCase();
        const digits = term.replace(/\D/g, '');

        const visible = this.contacts.filter((contact) => {
            if (!term) return true;
            const haystack = `${contact.name} ${contact.user.name} ${contact.user.username}`.toLowerCase();
            return haystack.includes(term.replace(/^@/, '')) || (digits.length >= 3 && contact.phone.replace(/\D/g, '').includes(digits));
        });

        this.el.count.textContent = this.loaded
            ? `${this.contacts.length} ${this.contacts.length === 1 ? 'contact' : 'contacts'} on ${this.chat.config.appName ?? 'the app'}`
            : 'Loading contacts…';

        if (!this.loaded) return;

        if (!this.contacts.length) {
            this.el.list.innerHTML = T.emptyState({
                iconName: 'users',
                title: 'No contacts yet',
                text: this.pickerSupported
                    ? 'Sync your phone contacts to see who is already here, or search by name, username or mobile number.'
                    : 'Import a .vcf file from your phone to see who is already here, or search by name, username or mobile number.',
            });
            return;
        }

        this.el.list.innerHTML =
            T.sectionTitle(term ? 'Matching contacts' : 'Contacts') +
            (visible.length
                ? visible.map((contact) => T.contactItem(contact, this.chat.presenceOf(contact.user))).join('')
                : html`<p class="contacts-none">No saved contacts match “${this.el.search.value.trim()}”.</p>`);
    }

    async searchPeople(term) {
        this.searchAbort?.abort();
        const controller = new AbortController();
        this.searchAbort = controller;

        try {
            const users = await this.chat.api.searchUsers(term, controller.signal);
            if (controller.signal.aborted || this.el.search.value.trim() !== term) return;

            const contactIds = new Set(this.contacts.map((c) => c.user.id));
            const others = users.filter((user) => !contactIds.has(user.id));
            users.forEach((user) => this.chat.rememberUser(user));

            this.el.results.hidden = false;
            this.el.results.innerHTML = others.length
                ? T.sectionTitle(`Other people on ${this.chat.config.appName ?? 'the app'}`) + others.map((user) => T.searchResultItem(user)).join('')
                : '';
        } catch (error) {
            if (error?.name !== 'CanceledError') this.el.results.hidden = true;
        }
    }

    async syncFromPhone() {
        if (!this.pickerSupported) return;
        if (this.native) return this.syncFromNativePhoneBook();
        try {
            const picked = await navigator.contacts.select(['name', 'tel'], { multiple: true });
            const entries = picked
                .map((contact) => ({ name: (contact.name ?? [])[0] ?? '', phones: (contact.tel ?? []).filter(Boolean).slice(0, 5) }))
                .filter((entry) => entry.phones.length);

            if (!entries.length) {
                if (picked.length) toast.info('The selected contacts have no phone numbers.');
                return;
            }
            this.upload(entries);
        } catch (error) {
            if (error?.name !== 'AbortError') toast.error('Could not open your phone contacts.');
        }
    }

    /**
     * Mobile app: read names and numbers from the phone book (asks for the
     * Contacts permission the first time).
     */
    async syncFromNativePhoneBook({ silent = false, quietDenied = false } = {}) {
        try {
            const { NativeApp } = await import('../native/plugins');
            const { contacts } = await NativeApp.getContacts();
            const entries = (contacts ?? [])
                .map((contact) => ({ name: contact.name ?? '', phones: (contact.phones ?? []).filter(Boolean).slice(0, 10) }))
                .filter((entry) => entry.phones.length);

            if (!entries.length) {
                if (!silent) toast.info('No phone numbers were found in your contacts.');
                return;
            }

            if (await this.upload(entries, { silent })) this.rememberSync();
        } catch (error) {
            if (silent || (quietDenied && error?.code === 'PERMISSION_DENIED')) return;
            toast.error(
                error?.code === 'PERMISSION_DENIED'
                    ? 'Allow Contacts access for the app in your phone settings to find your friends.'
                    : 'Could not read your phone contacts.',
            );
        }
    }

    /** Quietly refresh matches when the Contacts permission was already granted. */
    async autoSync() {
        if (Date.now() - this.lastSyncAt() < AUTO_SYNC_INTERVAL_MS) return;

        try {
            const { NativeApp } = await import('../native/plugins');
            const permissions = await NativeApp.checkPermissions();
            if (permissions?.contacts === 'granted') await this.syncFromNativePhoneBook({ silent: true });
        } catch {
            /* background refresh only */
        }
    }

    syncKey() {
        return `contacts:synced-at:${this.chat.me.id}`;
    }

    lastSyncAt() {
        try {
            return Number(localStorage.getItem(this.syncKey())) || 0;
        } catch {
            return 0;
        }
    }

    rememberSync() {
        try {
            localStorage.setItem(this.syncKey(), String(Date.now()));
        } catch {
            /* storage unavailable */
        }
    }

    async upload(entries, { silent = false } = {}) {
        const button = this.el.sync;
        this.el.view.classList.add('is-syncing');
        const note = silent ? null : toast.info(`Checking ${entries.length} ${entries.length === 1 ? 'contact' : 'contacts'}…`, { timeout: 0 });

        try {
            let latest = null;
            let matched = 0;
            for (let i = 0; i < entries.length; i += SYNC_BATCH) {
                const { data } = await axios.post(this.chat.api.url('contactsSync'), { contacts: entries.slice(i, i + SYNC_BATCH) });
                matched += data.matched.length;
                latest = data.data;
            }
            if (latest) this.setContacts(latest);

            note?.querySelector('.toast-close')?.click();
            if (silent) {
                // Background refresh: no toast.
            } else if (matched) {
                toast.success(`${matched} of your contacts ${matched === 1 ? 'is' : 'are'} on ${this.chat.config.appName ?? 'the app'}.`);
            } else {
                toast.info('None of these numbers are registered yet.');
            }
            return true;
        } catch (error) {
            note?.querySelector('.toast-close')?.click();
            if (!silent) toast.error(errorMessage(error, 'Contacts could not be synced.'));
            return false;
        } finally {
            this.el.view.classList.remove('is-syncing');
            button.blur();
        }
    }
}
