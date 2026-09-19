/**
 * Admin → person Money tab (Y2): adjust coins, freeze, grant plan/badge, void referrals.
 *
 * The forms carry `data-confirm`, so the global handler in app.js asks before submitting. This
 * module makes the questions specific ("Add 100 coins to Ali?"), keeps a fresh uuid `token` in
 * the adjust form (the ledger key `admin:{token}` makes a double submit a no-op) and blocks a
 * void without a reason.
 */

const uuid = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

export function initAdminWallet(root = document) {
    const panel = root.querySelector?.('[data-admin-wallet]') ?? null;
    const voids = root.querySelectorAll?.('form[data-referral-void]') ?? [];
    if (!panel && !voids.length) return null;

    const adjust = panel?.querySelector('form[data-coins-adjust]');
    if (adjust) {
        const token = adjust.querySelector('[data-coins-token]');
        const amount = adjust.querySelector('[name="amount"]');
        const note = adjust.querySelector('[name="note"]');
        const person = root.querySelector?.('.admin-person-name')?.textContent.trim() || 'this person';
        if (token && !token.value) token.value = uuid();

        const describe = () => {
            const n = Number(amount?.value ?? 0);
            if (!n) return;
            const verb = n > 0 ? 'Add' : 'Remove';
            adjust.dataset.confirmTitle = `${verb} ${Math.abs(n).toLocaleString()} coins?`;
            adjust.dataset.confirmLabel = verb;
            adjust.dataset.confirm = `${verb} ${Math.abs(n).toLocaleString()} coins ${n > 0 ? 'to' : 'from'} ${person}. Note: "${note?.value.trim() || '—'}". This is written to their coin history.`;
        };
        amount?.addEventListener('input', describe);
        note?.addEventListener('input', describe);
        describe();

        // A new token for every attempt: a second submit is never the same ledger key.
        adjust.addEventListener('submit', () => {
            if (adjust.dataset.confirmed !== '1' && token) token.value = uuid();
        });
    }

    const badge = panel?.querySelector('form[data-badge-grant]');
    const days = badge?.querySelector('[name="days"]');
    if (badge && days) {
        const describe = () => {
            const n = Number(days.value);
            badge.dataset.confirmTitle = n > 0 ? `Verified for ${n} days?` : 'Verified for good?';
            badge.dataset.confirm = n > 0
                ? `The tick shows next to their name for ${n} days (added after any current badge).`
                : 'No days given: the badge never expires until you remove it.';
        };
        days.addEventListener('input', describe);
        describe();
    }

    for (const form of voids) {
        form.addEventListener('submit', (event) => {
            const note = form.querySelector('[name="note"]');
            if (note && !note.value.trim()) {
                event.preventDefault();
                event.stopImmediatePropagation();
                note.focus();
                note.classList.add('is-invalid');
            }
        }, true);
    }

    return { panel, adjust: adjust ?? null };
}
