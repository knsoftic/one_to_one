/**
 * True when the page runs inside the One2One mobile app (Capacitor WebView).
 * The native bridge is injected before any page script runs.
 */
export function isNativeApp() {
    return window.Capacitor?.isNativePlatform?.() === true;
}
