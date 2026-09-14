// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { attachmentPreview, attachmentTray, conversationItem, messageYourselfItem, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => '' });

const me = { id: 1, name: 'Sara Khan', username: 'sara', initials: 'SK', avatar_hue: 200 };

describe('C7 message yourself', () => {
    it('offers "Message yourself" with my own id in the contacts panel', () => {
        const host = document.createElement('div');
        host.innerHTML = messageYourselfItem(me);

        expect(host.querySelector('[data-start-user-id]').dataset.startUserId).toBe('1');
        expect(host.querySelector('.conversation-name').textContent).toBe('Sara Khan (You)');
        expect(host.textContent).toContain('Message yourself');
    });

    it('lists the self chat under my name without an online dot', () => {
        const host = document.createElement('div');
        host.innerHTML = conversationItem({
            id: 5,
            is_self: true,
            participant: { ...me, name: 'Sara Khan (You)', is_online: false },
            last_message: { id: 9, preview: 'Buy milk', is_mine: true, status: 'seen', created_at: new Date().toISOString() },
            unread_count: 0,
            settings: {},
        });

        expect(host.querySelector('.conversation-name').textContent).toContain('Sara Khan (You)');
        expect(host.querySelector('.avatar.is-online')).toBeNull();
    });

    it('hides the view once switch when it is not offered', () => {
        const host = document.createElement('div');
        host.innerHTML = attachmentPreview({ type: 'image', name: 'p.jpg', size: 10, url: 'blob:x', viewOnce: null });
        expect(host.querySelector('[data-view-once-toggle]')).toBeNull();

        host.innerHTML = attachmentTray({
            items: [{ type: 'image', name: 'a.jpg', size: 1, url: 'blob:a' }, { type: 'video', name: 'b.mp4', size: 1, url: 'blob:b' }],
            activeIndex: 0,
            canAdd: true,
            viewOnce: null,
        });
        expect(host.querySelector('[data-view-once-toggle]')).toBeNull();
    });
});
