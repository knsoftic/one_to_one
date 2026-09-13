package com.hunario.chat;

import android.content.Context;

/**
 * Routes call events from Firebase push or the fallback WebSocket: rings in the open
 * app when the chat page can take it, otherwise with the full-screen call notification.
 */
final class CallDispatcher {

    private CallDispatcher() {}

    /** Call off the main thread. */
    static void incoming(Context context, NotificationSettings settings, IncomingCall call) {
        if (!call.isValid() || call.isExpired() || !settings.isUsable()) {
            return;
        }

        if (MainActivity.isInForeground()) {
            String script =
                "(function(){if(window.Chat&&window.Chat.calls&&window.Chat.calls.enabled){return window.Chat.calls.checkIncoming(" +
                call.callId +
                ");}return false;})()";
            if ("true".equals(MainActivity.evaluateInPage(script, 1500))) {
                return; // the app shows its own incoming-call screen
            }
        }

        CallNotifier.showIncoming(context, settings, call);
    }

    /** The call was answered (possibly on another device) or ended: stop ringing. */
    static void stateChanged(Context context, long callId, String status) {
        if (callId <= 0 || "ringing".equals(status)) {
            return;
        }
        CallNotifier.cancelIncoming(context, callId);
    }
}
