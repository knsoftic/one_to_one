import { html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { NativeApp } from './plugins';

const SESSION_KEY = 'native:permissions-asked';

/** Permissions the app asks for when it opens, in this order. */
const PERMISSIONS = [
    {
        name: 'notifications',
        icon: 'bell',
        title: 'Notifications',
        text: 'Get new messages even when the app is closed.',
    },
    {
        name: 'contacts',
        icon: 'contact',
        title: 'Contacts',
        text: 'See which of your saved contacts use the app. Numbers of people who aren’t registered are never stored.',
    },
    {
        name: 'microphone',
        icon: 'mic',
        title: 'Microphone',
        text: 'Voice calls, video calls and voice messages.',
    },
    {
        name: 'camera',
        icon: 'video',
        title: 'Camera',
        text: 'Video calls.',
    },
];

/**
 * Ask for the permissions the app needs. Permissions that are already granted
 * (or that the user blocked permanently) are skipped; the explanation is shown
 * at most once per app launch.
 *
 * @returns {Promise<Record<string, string>>} final state per permission
 */
export async function requestStartupPermissions(appName) {
    let states = await NativeApp.getPermissions();

    const missing = PERMISSIONS.filter((permission) => ['prompt', 'prompt-with-rationale'].includes(states[permission.name]));
    if (!missing.length || alreadyAskedThisLaunch()) return states;

    rememberAsked();

    const proceed = await explain(missing, appName);
    if (!proceed) return states;

    for (const permission of missing) {
        try {
            const result = await NativeApp.requestPermission({ name: permission.name });
            states = { ...states, [permission.name]: result.state };
        } catch {
            /* keep going with the next permission */
        }
    }

    return states;
}

function alreadyAskedThisLaunch() {
    try {
        return sessionStorage.getItem(SESSION_KEY) === '1';
    } catch {
        return false;
    }
}

function rememberAsked() {
    try {
        sessionStorage.setItem(SESSION_KEY, '1');
    } catch {
        /* storage unavailable */
    }
}

/**
 * Short explanation before the system dialogs appear.
 *
 * @returns {Promise<boolean>} true when the user chose to continue
 */
function explain(permissions, appName) {
    return new Promise((resolve) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'modal';
        wrapper.setAttribute('role', 'dialog');
        wrapper.setAttribute('aria-modal', 'true');
        wrapper.setAttribute('aria-labelledby', 'permissions-title');

        wrapper.innerHTML = html`
            <div class="modal-backdrop" data-permissions-later></div>
            <div class="modal-panel">
                <div class="modal-icon" style="background: var(--c-primary-soft); color: var(--c-primary)">${raw(icon('shield-check'))}</div>
                <h2 class="modal-title" id="permissions-title">Allow access</h2>
                <p class="modal-text">${appName} needs a few permissions to work like a messaging app. You can change them any time in your phone settings.</p>
                <ul class="permission-list">
                    ${raw(
                        permissions
                            .map(
                                (permission) => html`
                                    <li class="permission-item">
                                        <span class="permission-item-icon">${raw(icon(permission.icon))}</span>
                                        <span>
                                            <span class="permission-item-title">${permission.title}</span>
                                            <span class="permission-item-text">${permission.text}</span>
                                        </span>
                                    </li>
                                `,
                            )
                            .join(''),
                    )}
                </ul>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" data-permissions-later>Not now</button>
                    <button type="button" class="btn btn-primary" data-permissions-continue>Continue</button>
                </div>
            </div>
        `;

        const close = (value) => {
            document.removeEventListener('keydown', onKey);
            wrapper.remove();
            resolve(value);
        };

        const onKey = (event) => {
            if (event.key === 'Escape') close(false);
        };

        wrapper.addEventListener('click', (event) => {
            if (event.target.closest('[data-permissions-continue]')) close(true);
            else if (event.target.closest('[data-permissions-later]')) close(false);
        });

        document.addEventListener('keydown', onKey);
        document.body.appendChild(wrapper);
        wrapper.querySelector('[data-permissions-continue]').focus();
    });
}
