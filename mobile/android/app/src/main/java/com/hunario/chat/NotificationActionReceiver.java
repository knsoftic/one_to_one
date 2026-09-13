package com.hunario.chat;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Bundle;
import androidx.core.app.RemoteInput;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * Buttons on a message notification: Reply (typed in the notification) and Mark as read.
 * Both work while the app is closed, using the device token.
 */
public class NotificationActionReceiver extends BroadcastReceiver {

    static final String ACTION_REPLY = "com.hunario.chat.action.REPLY";
    static final String ACTION_MARK_READ = "com.hunario.chat.action.MARK_READ";
    static final String EXTRA_CONVERSATION_ID = "conversation_id";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null) {
            return;
        }

        final Context app = context.getApplicationContext();
        final String action = intent.getAction();
        final long conversationId = intent.getLongExtra(EXTRA_CONVERSATION_ID, 0);
        final NotificationSettings settings = NotificationSettings.load(app);

        if (conversationId <= 0 || !settings.isUsable()) {
            MessageNotifier.clearConversation(app, conversationId);
            return;
        }

        if (ACTION_MARK_READ.equals(action)) {
            MessageNotifier.clearConversation(app, conversationId);
            runAsync(() -> NotificationFeed.postJson(settings, NotificationSettings.forConversation(settings.readUrl, conversationId), new JSONObject()));
        } else if (ACTION_REPLY.equals(action)) {
            Bundle results = RemoteInput.getResultsFromIntent(intent);
            final CharSequence text = results == null ? null : results.getCharSequence(MessageNotifier.KEY_REPLY_TEXT);
            if (text == null || text.toString().trim().isEmpty()) {
                return;
            }

            runAsync(() -> {
                int status;
                try {
                    status = NotificationFeed.postJson(
                        settings,
                        NotificationSettings.forConversation(settings.replyUrl, conversationId),
                        new JSONObject().put("message", text.toString())
                    );
                } catch (JSONException exception) {
                    status = 0;
                }

                if (status >= 200 && status < 300) {
                    // Sent: the chat is read now, so the notification goes away (like WhatsApp).
                    MessageNotifier.clearConversation(app, conversationId);
                } else {
                    MessageNotifier.showReplyFailed(app, settings, conversationId, text);
                }
            });
        }
    }

    private void runAsync(Runnable task) {
        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                task.run();
            } finally {
                pending.finish();
            }
        }, "one2one-notification-action").start();
    }
}
