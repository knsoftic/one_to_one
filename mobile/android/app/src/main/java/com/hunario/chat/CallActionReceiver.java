package com.hunario.chat;

import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import org.json.JSONObject;

/**
 * Call buttons outside the app screen: Decline on an incoming call, Hang up on the
 * ongoing-call notification. Work while the app is closed (device token).
 */
public class CallActionReceiver extends BroadcastReceiver {

    static final String ACTION_DECLINE = "com.hunario.chat.action.CALL_DECLINE";
    static final String ACTION_HANGUP = "com.hunario.chat.action.CALL_HANGUP";
    static final String EXTRA_CALL_ID = "com.hunario.chat.call_id";

    static PendingIntent declineIntent(Context context, long callId) {
        return broadcast(context, ACTION_DECLINE, callId, 2);
    }

    static PendingIntent hangUpIntent(Context context, long callId) {
        return broadcast(context, ACTION_HANGUP, callId, 3);
    }

    private static PendingIntent broadcast(Context context, String action, long callId, int code) {
        Intent intent = new Intent(context, CallActionReceiver.class).setAction(action).putExtra(EXTRA_CALL_ID, callId);
        return PendingIntent.getBroadcast(
            context,
            (int) (callId * 3 + code) | 0x20000000,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );
    }

    /** Hang up without the web app (e.g. the app was swiped away during a call). */
    static void endCall(Context context, long callId) {
        if (callId > 0) {
            send(context.getApplicationContext(), callId, false, null);
        }
    }

    /** Decline from the incoming-call screen or notification. */
    static void decline(Context context, long callId) {
        Context app = context.getApplicationContext();
        CallNotifier.cancelIncoming(app, callId);
        send(app, callId, true, null);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) {
            return;
        }
        final Context app = context.getApplicationContext();
        final long callId = intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (callId <= 0) {
            return;
        }

        if (ACTION_DECLINE.equals(intent.getAction())) {
            CallNotifier.cancelIncoming(app, callId);
            send(app, callId, true, goAsync());
        } else if (ACTION_HANGUP.equals(intent.getAction())) {
            // The web app ends the call (closing WebRTC); without it, tell the server directly.
            if (!NativeAppPlugin.emitCallAction("hangup", callId)) {
                OngoingCallService.stop(app);
                send(app, callId, false, goAsync());
            }
        }
    }

    private static void send(Context app, long callId, boolean decline, PendingResult pending) {
        NotificationSettings settings = NotificationSettings.load(app);
        String template = decline ? settings.callDeclineUrl : settings.callEndUrl;

        if (!settings.isUsable() || template.isEmpty()) {
            if (pending != null) {
                pending.finish();
            }
            return;
        }

        new Thread(() -> {
            try {
                NotificationFeed.postJson(settings, NotificationSettings.forConversation(template, callId), new JSONObject());
            } finally {
                if (pending != null) {
                    pending.finish();
                }
            }
        }, "one2one-call-action").start();
    }
}
