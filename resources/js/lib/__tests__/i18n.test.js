// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import { createTranslator, initI18n, t, translateTitle, translateTree, watchTranslations } from '../i18n';

const dictionary = {
    Save: 'محفوظ کریں',
    Settings: 'ترتیبات',
    'Search or start a new chat': 'تلاش کریں یا نئی چیٹ شروع کریں',
    online: 'آن لائن',
    'Delete chat?': 'چیٹ حذف کریں؟',
    '{0} is now {1}': 'اب {0} {1} ہے',
    '{0} is {1}': '{0} {1} ہے',
    '{0} chat{1} in this list.': 'اس فہرست میں {0} چیٹس۔',
    '{0} {1}': '{1} {0}',
    Same: 'Same',
};

describe('X1 translator', () => {
    const translator = createTranslator(dictionary);

    it('translates whole strings, keeping the spaces around them', () => {
        expect(translator.translate('Save')).toBe('محفوظ کریں');
        expect(translator.translate('\n      Save\n   ')).toBe(' محفوظ کریں ');
        expect(translator.translate('Search   or start\n a new chat')).toBe('تلاش کریں یا نئی چیٹ شروع کریں');
        expect(translator.translate('Saved')).toBe('Saved');
        expect(translator.translate('اردو')).toBe('اردو');
    });

    it('fills values into patterns and translates known values', () => {
        expect(translator.translate('Ayesha is now online')).toBe('اب Ayesha آن لائن ہے');
        expect(translator.translate('Ayesha is online')).toBe('Ayesha is online');
        expect(translator.translate('3 chats in this list.')).toBe('اس فہرست میں 3 چیٹس۔');
        expect(translator.translate('1 chat in this list.')).toBe('اس فہرست میں 1 چیٹس۔');
        // A value never runs across a sentence end.
        const groups = createTranslator({ '{0} added {1}': '{0} نے {1} کو شامل کیا', '{0} files deleted ({1}).': '{0} فائلیں حذف ({1})۔' });
        expect(groups.translate('Ali added Sara')).toBe('Ali نے Sara کو شامل کیا');
        expect(groups.translate('Hello. +92 is added for you — start with +.')).toBe('Hello. +92 is added for you — start with +.');
        expect(groups.translate('3 files deleted (2.4 MB).')).toBe('3 فائلیں حذف (2.4 MB)۔');
        // Patterns without enough fixed text are ignored.
        expect(translator.translate('Hello there')).toBe('Hello there');
        expect(translator.lookup('Same')).toBeNull();
    });

    it('translates the page but never people\'s own words', () => {
        document.body.innerHTML = `
            <button title="Save">Save</button>
            <input placeholder="Search or start a new chat">
            <input type="submit" value="Save">
            <div class="message-text" translate="no">Save</div>
            <textarea>Save</textarea>
            <form data-confirm-title="Delete chat?"><span>Settings</span></form>`;
        translateTree(document, translator);

        expect(document.querySelector('button').textContent).toBe('محفوظ کریں');
        expect(document.querySelector('button').title).toBe('محفوظ کریں');
        expect(document.querySelector('input').placeholder).toBe('تلاش کریں یا نئی چیٹ شروع کریں');
        expect(document.querySelector('input[type=submit]').value).toBe('محفوظ کریں');
        expect(document.querySelector('.message-text').textContent).toBe('Save');
        expect(document.querySelector('textarea').value).toBe('Save');
        expect(document.querySelector('form').dataset.confirmTitle).toBe('چیٹ حذف کریں؟');
        expect(document.querySelector('span').textContent).toBe('ترتیبات');
    });

    it('keeps translating what the app adds later', async () => {
        document.body.innerHTML = '<div id="list"></div>';
        const observer = watchTranslations(document.body, translator);
        const list = document.getElementById('list');
        list.innerHTML = '<span class="status">online</span><p translate="no"><b>online</b></p>';
        await Promise.resolve();
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(list.querySelector('.status').textContent).toBe('آن لائن');
        expect(list.querySelector('b').textContent).toBe('online');

        list.querySelector('.status').textContent = 'Settings';
        list.setAttribute('aria-label', 'Save');
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(list.querySelector('.status').textContent).toBe('ترتیبات');
        expect(list.getAttribute('aria-label')).toBe('محفوظ کریں');
        observer.disconnect();
    });

    it('translates the page name in the title', () => {
        document.title = 'Settings · One2One Chat';
        translateTitle(translator);
        expect(document.title).toBe('ترتیبات · One2One Chat');
    });
});

describe('X1 start-up', () => {
    afterEach(() => {
        document.documentElement.setAttribute('lang', 'en');
        document.documentElement.removeAttribute('data-i18n-pending');
    });

    it('does nothing in English but shows the page', async () => {
        document.documentElement.setAttribute('lang', 'en');
        document.documentElement.setAttribute('data-i18n-pending', '');
        let loaded = false;
        expect(await initI18n(async () => { loaded = true; return dictionary; })).toBeNull();
        expect(loaded).toBe(false);
        expect(document.documentElement.hasAttribute('data-i18n-pending')).toBe(false);
        expect(t('Save')).toBe('Save');
    });

    it('translates in Urdu and shows the page even when loading fails', async () => {
        document.documentElement.setAttribute('lang', 'ur');
        document.documentElement.setAttribute('data-i18n-pending', '');
        document.body.innerHTML = '<h1>Settings</h1>';
        await initI18n(async () => dictionary);
        expect(document.querySelector('h1').textContent).toBe('ترتیبات');
        expect(t('Save')).toBe('محفوظ کریں');
        expect(document.documentElement.hasAttribute('data-i18n-pending')).toBe(false);

        document.documentElement.setAttribute('data-i18n-pending', '');
        const originalError = console.error;
        console.error = () => {};
        await initI18n(async () => { throw new Error('offline'); });
        console.error = originalError;
        expect(document.documentElement.hasAttribute('data-i18n-pending')).toBe(false);
    });
});
