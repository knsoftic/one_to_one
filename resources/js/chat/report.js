import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * Phase 6 — P6: report someone from their chat or contact info.
 */
export const REPORT_REASONS = [
    ['spam', 'Spam'],
    ['abuse', 'Abusive or harassing'],
    ['fake', 'Fake account or scam'],
    ['other', 'Something else'],
];

export class ReportUser {
    constructor(chat) {
        this.chat = chat;
        if (!chat.api.has('reportUser')) return;

        document.addEventListener('chat:menu', (event) => {
            const { conversation, items } = event.detail;
            if (conversation?.type !== 'direct' || conversation.is_self || !conversation.participant) return;
            items.push(html`<button type="button" class="dropdown-item is-danger" data-action="report-user" role="menuitem">${raw(icon('circle-alert'))} Report</button>`);
        });

        document.addEventListener('chat:action', (event) => {
            if (event.detail.action === 'report-user') this.open(this.chat.activeConversation());
        });
    }

    open(conversation) {
        const person = conversation?.participant;
        if (!person) return;
        const name = this.chat.participantOf?.(conversation)?.name ?? person.name;

        const previouslyFocused = document.activeElement;
        const overlay = document.createElement('div');
        overlay.className = 'modal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'report-title');
        overlay.innerHTML = html`
            <div class="modal-backdrop" data-report-cancel></div>
            <form class="modal-panel group-form report-form" novalidate>
                <h2 class="modal-title" id="report-title">Report ${name}?</h2>
                <p class="group-form-hint">The last 5 messages from ${name} in this chat will be sent to the admins to review. ${name} won't be told.</p>
                <div class="report-reasons" role="radiogroup" aria-label="Why are you reporting?">
                    ${raw(REPORT_REASONS.map(([value, label]) => html`
                        <label class="status-privacy-option"><input type="radio" name="reason" value="${value}"><span><strong>${label}</strong></span></label>`).join(''))}
                </div>
                <textarea class="form-control" name="details" rows="2" maxlength="1000" placeholder="Anything else admins should know (optional)"></textarea>
                <label class="poll-multiple"><input type="checkbox" name="block" checked> Also block ${name}</label>
                <p class="poll-error" data-report-error hidden></p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-report-cancel>Cancel</button>
                    <button type="submit" class="btn btn-danger">Report</button>
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
            if (event.target.closest('[data-report-cancel]')) close();
        });
        overlay.querySelector('form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.target;
            const reason = form.querySelector('input[name="reason"]:checked')?.value;
            const error = overlay.querySelector('[data-report-error]');
            if (!reason) {
                error.hidden = false;
                error.textContent = 'Choose why you are reporting.';
                return;
            }
            const block = form.elements.namedItem('block').checked;
            form.querySelector('[type="submit"]').disabled = true;
            try {
                await this.chat.api.reportUser(person.id, {
                    reason,
                    details: form.elements.namedItem('details').value.trim(),
                    conversation_id: conversation.id,
                    block,
                });
                close();
                toast.success(block ? `${name} was reported and blocked.` : `${name} was reported. Thanks for helping keep everyone safe.`);
                if (block) this.chat.blocks?.apply?.(conversation.id, { blocked_by_me: true });
            } catch (err) {
                error.hidden = false;
                error.textContent = errorMessage(err, "Couldn't send the report.");
                form.querySelector('[type="submit"]').disabled = false;
            }
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(overlay);
        overlay.querySelector('input[name="reason"]').focus();
    }
}
