import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { TONES, VIBRATIONS, playTone, toneLabel, vibrate, vibrationLabel } from '../lib/tones';

let audioContext = null;

/** Hear a tone while choosing it. */
export function previewTone(tone) {
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context || !tone || tone === 'none') return false;
    audioContext ??= new Context();
    audioContext.resume?.()?.catch?.(() => {});
    return playTone(audioContext, tone);
}

/**
 * D4 — a chat's own notification tone and vibration (chat menu → Notification tone).
 */
export class ChatTone {
    constructor(chat) {
        this.chat = chat;
        if (!chat.api.has('conversationSettings')) return;

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (!this.canChoose(conversation)) return;
            const tone = conversation.settings?.notification_tone;
            items.push(html`<button type="button" class="dropdown-item" data-action="notification-tone" role="menuitem">${raw(icon('bell-ring'))} Notification tone<span class="dropdown-item-hint">${tone ? toneLabel(tone) : 'Default'}</span></button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'notification-tone') this.open();
        });
    }

    /** Channels and broadcast lists never notify; notes to self neither. */
    canChoose(conversation) {
        return Boolean(conversation) && !conversation.is_locked_out && !conversation.is_self && !['channel', 'broadcast'].includes(conversation.type);
    }

    get defaults() {
        const user = this.chat.config?.user ?? {};
        return {
            tone: user.notification_sound === false ? 'none' : user.notification_tone || 'default',
            vibrate: user.notification_vibrate || 'default',
        };
    }

    open() {
        const conversation = this.chat.activeConversation();
        if (!this.canChoose(conversation)) return;

        const conversationId = conversation.id;
        const currentTone = conversation.settings?.notification_tone || 'default';
        const currentVibrate = conversation.settings?.notification_vibrate || 'default';
        const defaults = this.defaults;
        const previouslyFocused = document.activeElement;

        const choices = [
            { key: 'default', label: defaults.tone === 'default' ? 'Default' : `Default (${toneLabel(defaults.tone)})` },
            ...TONES.filter((tone) => tone.key !== 'default'),
            { key: 'none', label: 'None' },
        ];

        const overlay = document.createElement('div');
        overlay.className = 'modal tone-dialog';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'tone-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-tone-cancel></div>
            <form class="modal-panel tone-panel" data-tone-form>
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('bell-ring'))}</div>
                <h2 class="modal-title" id="tone-title">Notification tone</h2>
                <p class="modal-text">Only for this chat. Tap a sound to hear it.</p>
                <fieldset class="tone-group">
                    <legend>Sound</legend>
                    <div class="tone-options">
                        ${raw(choices.map((choice) => html`
                            <label class="tone-option">
                                <input type="radio" name="tone" value="${choice.key}" ${raw(choice.key === currentTone ? 'checked' : '')}>
                                <span class="tone-option-icon">${raw(icon(choice.key === 'none' ? 'bell-off' : 'music'))}</span>
                                <span>${choice.label}</span>
                            </label>`).join(''))}
                    </div>
                </fieldset>
                <fieldset class="tone-group">
                    <legend>Vibration</legend>
                    <div class="tone-segments">
                        ${raw(VIBRATIONS.map((item) => html`
                            <label class="tone-segment">
                                <input type="radio" name="vibrate" value="${item.key}" ${raw(item.key === currentVibrate ? 'checked' : '')}>
                                <span>${item.key === 'default' && defaults.vibrate !== 'default' ? `Default (${vibrationLabel(defaults.vibrate)})` : item.label}</span>
                            </label>`).join(''))}
                    </div>
                </fieldset>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-tone-cancel>Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        `;

        const close = () => {
            document.removeEventListener('keydown', onKey);
            overlay.remove();
            previouslyFocused?.focus?.();
        };
        const onKey = (event) => {
            if (event.key === 'Escape') close();
        };

        overlay.addEventListener('click', (event) => {
            if (event.target.closest('[data-tone-cancel]')) close();
        });
        overlay.addEventListener('change', (event) => {
            if (event.target.name === 'tone') previewTone(event.target.value === 'default' ? defaults.tone : event.target.value);
            if (event.target.name === 'vibrate') vibrate(event.target.value === 'default' ? defaults.vibrate : event.target.value);
        });
        overlay.querySelector('[data-tone-form]').addEventListener('submit', (event) => {
            event.preventDefault();
            const form = new FormData(event.target);
            const tone = String(form.get('tone') || 'default');
            const pattern = String(form.get('vibrate') || 'default');
            close();
            if (tone !== currentTone || pattern !== currentVibrate) this.save(conversationId, tone, pattern);
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input[name="tone"]:checked')?.focus();
    }

    async save(conversationId, tone, pattern) {
        try {
            const fresh = await this.chat.api.updateChatSettings(conversationId, {
                notification_tone: tone === 'default' ? null : tone,
                notification_vibrate: pattern === 'default' ? null : pattern,
            });
            this.chat.upsertConversation(fresh);
            toast.success('Notification tone saved.', { timeout: 2000 });
        } catch (error) {
            toast.error(errorMessage(error, 'Could not save the notification tone.'));
        }
    }
}
