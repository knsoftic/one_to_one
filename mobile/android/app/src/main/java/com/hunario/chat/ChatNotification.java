package com.hunario.chat;

import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.Map;
import org.json.JSONObject;

/**
 * One incoming message to show as a notification, whichever way it arrived
 * (Firebase push, WebSocket or the polling feed).
 */
final class ChatNotification {

    final String id;
    final long conversationId;
    final long messageId;
    final long senderId;
    final String senderName;
    final String body;
    final String avatarUrl;
    final String initials;
    final int avatarHue;
    final long timestamp;
    /** D4: sound ("default", "none" or one of the app's tones) and vibration of this chat. */
    final String tone;
    final String vibrate;

    private ChatNotification(
        String id,
        long conversationId,
        long messageId,
        long senderId,
        String senderName,
        String body,
        String avatarUrl,
        String initials,
        int avatarHue,
        long timestamp,
        String tone,
        String vibrate
    ) {
        this.id = id;
        this.conversationId = conversationId;
        this.messageId = messageId;
        this.senderId = senderId;
        this.senderName = senderName;
        this.body = body;
        this.avatarUrl = avatarUrl;
        this.initials = initials;
        this.avatarHue = avatarHue;
        this.timestamp = timestamp > 0 ? timestamp : System.currentTimeMillis();
        this.tone = tone == null || tone.isEmpty() ? "default" : tone;
        this.vibrate = vibrate == null || vibrate.isEmpty() ? "default" : vibrate;
    }

    /** Firebase data message (all values are strings). */
    static ChatNotification fromPush(Map<String, String> data) {
        return new ChatNotification(
            value(data, "id"),
            number(value(data, "conversation_id")),
            number(value(data, "message_id")),
            number(value(data, "sender_id")),
            value(data, "sender_name"),
            value(data, "body"),
            value(data, "avatar_url"),
            value(data, "initials"),
            (int) number(value(data, "avatar_hue")),
            number(value(data, "sent_at")),
            value(data, "tone"),
            value(data, "vibrate")
        );
    }

    /** Realtime event or polling feed item (NewMessageNotification payload). */
    static ChatNotification fromJson(JSONObject item) {
        JSONObject sender = item.optJSONObject("sender");
        String name = NotificationFeed.text(sender, "display_name");
        if (name.isEmpty()) {
            name = NotificationFeed.text(sender, "name");
        }

        return new ChatNotification(
            NotificationFeed.text(item, "id"),
            item.optLong("conversation_id", 0),
            item.optLong("message_id", 0),
            sender == null ? 0 : sender.optLong("id", 0),
            name,
            NotificationFeed.text(item, "body"),
            NotificationFeed.text(sender, "avatar_url"),
            NotificationFeed.text(sender, "initials"),
            sender == null ? 0 : sender.optInt("avatar_hue", 0),
            parseIso(NotificationFeed.text(item, "created_at")),
            NotificationFeed.text(item, "tone"),
            NotificationFeed.text(item, "vibrate")
        );
    }

    private static String value(Map<String, String> data, String key) {
        String value = data.get(key);
        return value == null ? "" : value.trim();
    }

    private static long number(String value) {
        try {
            return Long.parseLong(value);
        } catch (NumberFormatException exception) {
            return 0;
        }
    }

    private static long parseIso(String value) {
        if (value.isEmpty()) {
            return 0;
        }
        try {
            Date date = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US).parse(value);
            return date == null ? 0 : date.getTime();
        } catch (ParseException exception) {
            return 0;
        }
    }
}
