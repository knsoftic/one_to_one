package com.hunario.chat;

import androidx.annotation.NonNull;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Receives Firebase push messages — also when the app is closed — and shows
 * the notification itself (sender photo, conversation style, Reply / Mark as read).
 */
public class PushMessagingService extends FirebaseMessagingService {

    @Override
    public void onNewToken(@NonNull String token) {
        // Runs on a background thread.
        PushRegistrar.uploadToken(getApplicationContext(), token);
    }

    @Override
    public void onMessageReceived(@NonNull RemoteMessage remoteMessage) {
        NotificationSettings settings = NotificationSettings.load(this);
        if (!settings.isUsable()) {
            return; // signed out on this phone
        }

        Map<String, String> data = remoteMessage.getData();
        String type = data.get("type");

        if ("message".equals(type)) {
            ChatNotification message = ChatNotification.fromPush(data);
            NotificationFeed.present(this, settings, message);

            List<Long> ids = new ArrayList<>();
            ids.add(message.messageId);
            NotificationFeed.markDelivered(settings, ids);
        } else if ("read".equals(type)) {
            String conversationId = data.get("conversation_id");
            if (conversationId != null) {
                try {
                    MessageNotifier.clearConversation(this, Long.parseLong(conversationId));
                } catch (NumberFormatException ignored) {
                    // Malformed payload.
                }
            }
        }
    }
}
