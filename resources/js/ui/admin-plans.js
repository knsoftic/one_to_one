/**
 * Admin → Plans (Y2): the plan editor. A benefit switch reveals its numeric fields
 * (`data-plan-toggle="x"` → `[data-plan-fields="x"]`) and clears them when switched off, so
 * the server never receives coins or limits the admin did not mean; the slug follows the name
 * until the admin edits it.
 */
export function initAdminPlans(root = document) {
    const form = root.querySelector?.('[data-plan-editor]');
    if (!form) return null;

    const fieldsFor = (name) => form.querySelector(`[data-plan-fields="${name}"]`);

    for (const toggle of form.querySelectorAll('[data-plan-toggle]')) {
        const fields = fieldsFor(toggle.dataset.planToggle);
        if (!fields) continue;
        const sync = () => {
            fields.hidden = !toggle.checked;
            if (!toggle.checked) {
                for (const input of fields.querySelectorAll('input')) input.value = '';
            } else {
                fields.querySelector('input')?.focus();
            }
        };
        toggle.addEventListener('change', sync);
        fields.hidden = !toggle.checked;
    }

    // Slug: suggested from the name until the admin types their own.
    const name = form.querySelector('[data-plan-name]');
    const slug = form.querySelector('[data-plan-slug]');
    if (name && slug) {
        let auto = !slug.value;
        slug.addEventListener('input', () => {
            auto = !slug.value;
        });
        name.addEventListener('input', () => {
            if (auto) slug.value = slugify(name.value);
        });
    }

    return { form };
}

/** "Pro Monthly!" → "pro-monthly" (lowercase letters, numbers and single hyphens). */
export function slugify(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
}
