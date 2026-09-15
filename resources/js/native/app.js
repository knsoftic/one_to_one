import axios from '../bootstrap';
import { confirmDialog } from '../lib/modal';
import { checkAndroidUpdate } from '../ui/app-update';
import { AppLock } from './app-lock';
import { requestStartupPermissions } from './permissions';
import { App, NativeApp, SystemBars, SystemBarsStyle } from './plugins';

/** Open overlays that the Android back button should close first. */
const OVERLAYS = '.modal, .lightbox:not(.is-closing), .dropdown-menu:not([hidden]), .emoji-panel, .group-info';

/** Pages where "back" leaves the app instead of navigating. */
const ROOT_PATHS = ['', '/chat', '/login', '/register'];

const BATTERY_PROMPT_KEY = 'native:battery-prompted';
const DEVICE_CONFIG_KEY = 'native:device-config';

function storedValue(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function storeValue(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        /* storage unavailable: the app simply registers again next time */
    }
}

let appConfig = {};

/**
 * Native integration for the Android app: status bar, back button and
 * message notifications. Loaded only when the page runs inside the app.
 */
export function initNativeApp(config) {
    appConfig = config ?? {};
    document.documentElement.classList.add('is-native-app');

    syncSystemBars();
    document.addEventListener('theme:change', syncSystemBars);

    bindBackButton();

    // X4: a newer app version was published in the admin panel.
    checkAndroidUpdate(appConfig, NativeApp).catch(() => {});

    // App lock (Phase 6, P8): fingerprint / face / screen lock when the app opens.
    new AppLock({ NativeApp, App, appName: appConfig.name ?? 'One2One' })
        .init({ signedIn: Boolean(appConfig.user) })
        .catch(() => {});

    // People expect to stay signed in on their own phone.
    const remember = document.querySelector('form input[type="checkbox"][name="remember"]');
    if (remember && !remember.checked) remember.checked = true;

    if (appConfig.user && document.querySelector('[data-chat-app]')) {
        trackActiveConversation();
        bindCalls();
        setUpChatPage().catch((error) => console.warn('App setup incomplete:', error?.message ?? error));
    }

    // Signing out: stop this phone's notifications right away.
    document.addEventListener(
        'submit',
        (event) => {
            if (appConfig.routes?.logout && event.target.action === appConfig.routes.logout) {
                NativeApp.disableNotifications().catch(() => {});
            }
        },
        true,
    );
}

/* ---------------------------------------------------------------------- */
/* Status bar                                                              */
/* ---------------------------------------------------------------------- */

function syncSystemBars() {
    const dark = document.documentElement.dataset.theme === 'dark';
    // Chats, settings and sign-in have the indigo gradient behind the status bar.
    const gradientTop = Boolean(document.querySelector('[data-chat-app], [data-settings], .auth-shell'));
    // "Dark" = light icons for a dark page, "Light" = dark icons for a light page.
    SystemBars.setStyle({ style: dark || gradientTop ? SystemBarsStyle.Dark : SystemBarsStyle.Light }).catch(() => {});
}

/* ---------------------------------------------------------------------- */
/* Back button                                                             */
/* ---------------------------------------------------------------------- */

function bindBackButton() {
    App.addListener('backButton', ({ canGoBack }) => {
        if (document.querySelector(OVERLAYS)) {
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            return;
        }

        // Let the page handle it first (close a chat, contacts panel, reply mode…).
        const event = new CustomEvent('app:back', { cancelable: true });
        document.dispatchEvent(event);
        if (event.defaultPrevented) return;

        const path = window.location.pathname.replace(/\/+$/, '');
        if (ROOT_PATHS.includes(path)) {
            App.minimizeApp().catch(() => App.exitApp());
        } else if (canGoBack) {
            window.history.back();
        } else {
            window.location.assign(appConfig.routes?.chat ?? '/');
        }
    });
}

/* ---------------------------------------------------------------------- */
/* Message notifications (the app's own background connection, no Firebase) */
/* ---------------------------------------------------------------------- */

/**
 * When the chat opens: ask for the permissions the app needs (skipping those
 * already granted), then switch on phone notifications and contact matching.
 */
async function setUpChatPage() {
    const states = await requestStartupPermissions(appConfig.name ?? 'One2One Chat');

    if (states.contacts === 'granted') {
        window.Chat?.contactsPanel?.autoSync();
    }

    if (states.notifications === 'granted') {
        await enableNotifications();

        // Firebase push needs nothing more (like WhatsApp). Only the fallback background
        // connection needs permission to keep running.
        const mode = await notificationMode();
        if (mode === 'socket' && !states.batteryUnrestricted) askToKeepConnected();
    }
}

/** Wait briefly until the app has chosen Firebase push or its own connection. */
async function notificationMode() {
    for (let attempt = 0; attempt < 12; attempt++) {
        const { mode } = await NativeApp.getNotificationStatus().catch(() => ({}));
        if (mode) return mode;
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    return '';
}

async function enableNotifications() {
    if (!appConfig.routes?.devices) return;

    const status = await NativeApp.getNotificationStatus();

    // Register again when the phone's saved settings are outdated: another account, an older
    // app version without call endpoints, or server changes such as Firebase being switched
    // on or the WebSocket address being fixed (otherwise the phone would stay on the slow
    // fallback connection and miss calls).
    const serverVersion = `${appConfig.user.id}:${appConfig.mobile?.configVersion ?? ''}`;
    const outdated =
        !status.enabled ||
        Number(status.userId) !== Number(appConfig.user.id) ||
        status.callsReady === false ||
        storedValue(DEVICE_CONFIG_KEY) !== serverVersion;

    if (outdated) {
        const info = await NativeApp.getInfo().catch(() => ({}));
        const { data } = await axios.post(appConfig.routes.devices, {
            platform: 'android',
            app_version: info.version ?? null,
        });
        await NativeApp.enableNotifications(data);
        storeValue(DEVICE_CONFIG_KEY, `${appConfig.user.id}:${data.config_version ?? ''}`);
    }

    // New messages now arrive as phone notifications (also while the app is open),
    // so the web app skips its own toast and chime.
    if (window.Chat) window.Chat.nativeNotifications = true;
}

/**
 * Calls inside the app: the phone's own ringtone, the ongoing-call service (keeps
 * talking with the screen off or in another app), speaker / earpiece and Hang up
 * from the notification.
 */
function bindCalls() {
    const attach = () => {
        window.Chat?.calls?.setNativeBridge({
            ringtone: true,
            setSpeaker: (on) => NativeApp.setSpeakerphone({ on }).catch(() => {}),
        });
    };
    if (window.Chat?.calls) attach();
    else document.addEventListener('chat:ready', attach, { once: true });

    document.addEventListener('call:state', ({ detail }) => {
        const { state, call, type, peer, speaker } = detail;

        switch (state) {
            case 'incoming':
                NativeApp.startRingtone().catch(() => {});
                break;
            case 'incoming-answered':
            case 'incoming-dismissed':
                NativeApp.stopRingtone().catch(() => {});
                break;
            case 'outgoing':
            case 'active':
                NativeApp.startCall({
                    callId: call?.id ?? 0,
                    conversationId: call?.conversation_id ?? 0,
                    type: type ?? 'audio',
                    name: peer?.name ?? '',
                    speaker: Boolean(speaker),
                }).catch(() => {});
                break;
            case 'connected':
                NativeApp.updateCall({ connectedAt: Date.now() }).catch(() => {});
                break;
            case 'ended':
                NativeApp.endCall().catch(() => {});
                break;
            default:
                break;
        }
    });

    NativeApp.addListener('callAction', ({ action }) => {
        if (action === 'hangup') window.Chat?.calls?.hangUp();
    });
}

/** Tell the app which chat is on screen: its messages don't need a phone notification. */
function trackActiveConversation() {
    const report = (conversationId) => NativeApp.setActiveConversation({ conversationId: conversationId ?? 0 }).catch(() => {});

    document.addEventListener('chat:opened', (event) => {
        const conversationId = event.detail?.conversation?.id;
        report(conversationId);
        if (conversationId) NativeApp.clearConversationNotifications({ conversationId }).catch(() => {});
    });
    document.addEventListener('chat:closed', () => report(0));
    window.addEventListener('pagehide', () => report(0));

    report(window.Chat?.active?.id ?? 0);
}

/**
 * Android may pause apps in the background; exempting the app keeps messages
 * arriving instantly. Asked once per install.
 */
async function askToKeepConnected() {
    try {
        if (localStorage.getItem(BATTERY_PROMPT_KEY)) return;
        localStorage.setItem(BATTERY_PROMPT_KEY, String(Date.now()));
    } catch {
        return;
    }

    const choice = await confirmDialog({
        title: 'Get messages instantly',
        message: `Allow ${appConfig.name ?? 'the app'} to stay connected in the background, so new messages arrive even when the app is closed.`,
        icon: 'bell',
        tone: 'primary',
        cancelLabel: 'Not now',
        actions: [{ label: 'Allow', value: 'allow', variant: 'primary' }],
    });

    if (choice === 'allow') NativeApp.requestBatteryOptimizationExemption().catch(() => {});
}
