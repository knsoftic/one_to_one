package com.hunario.chat;

import android.content.Context;
import android.content.SharedPreferences;
import android.text.TextUtils;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.LinkedHashSet;
import java.util.List;
import org.json.JSONObject;

/**
 * Connection details for notifications, stored in app-private preferences.
 * Filled from the server's POST /devices response.
 */
final class NotificationSettings {

    /** Notifications arrive through Firebase Cloud Messaging. */
    static final String MODE_PUSH = "push";
    /** Notifications arrive through the app's own background connection. */
    static final String MODE_SOCKET = "socket";

    private static final String PREFS = "one2one_notifications";
    private static final int MAX_REMEMBERED_IDS = 150;

    final boolean enabled;
    final String token;
    final long userId;
    final String serverUrl;
    final String channel;
    final String websocketUrl;
    final String authUrl;
    final String feedUrl;
    final String revokeUrl;
    final String pushTokenUrl;
    final String deliveredUrl;
    final String replyUrl;
    final String readUrl;
    final boolean serverPush;
    final String mode;
    final int pollIntervalSeconds;
    final boolean showPreview;

    private NotificationSettings(SharedPreferences prefs) {
        enabled = prefs.getBoolean("enabled", false);
        token = prefs.getString("token", "");
        userId = prefs.getLong("user_id", 0);
        serverUrl = prefs.getString("server_url", "");
        channel = prefs.getString("channel", "");
        websocketUrl = prefs.getString("websocket_url", "");
        authUrl = prefs.getString("auth_url", "");
        feedUrl = prefs.getString("feed_url", "");
        revokeUrl = prefs.getString("revoke_url", "");
        pushTokenUrl = prefs.getString("push_token_url", "");
        deliveredUrl = prefs.getString("delivered_url", "");
        replyUrl = prefs.getString("reply_url", "");
        readUrl = prefs.getString("read_url", "");
        serverPush = prefs.getBoolean("server_push", false);
        mode = prefs.getString("mode", "");
        pollIntervalSeconds = Math.max(15, prefs.getInt("poll_interval", 60));
        showPreview = prefs.getBoolean("show_preview", true);
    }

    static NotificationSettings load(Context context) {
        return new NotificationSettings(prefs(context));
    }

    boolean isUsable() {
        return enabled && !token.isEmpty() && !feedUrl.isEmpty();
    }

    boolean usesPush() {
        return MODE_PUSH.equals(mode);
    }

    boolean hasWebSocket() {
        return !websocketUrl.isEmpty() && !authUrl.isEmpty() && !channel.isEmpty();
    }

    /** Endpoint for a conversation (the server sends templates with __ID__). */
    static String forConversation(String template, long conversationId) {
        return template.replace("__ID__", String.valueOf(conversationId));
    }

    /** Save the server's connection details and switch notifications on. */
    static void save(Context context, JSONObject details) {
        JSONObject endpoints = details.optJSONObject("endpoints");
        if (endpoints == null) {
            endpoints = new JSONObject();
        }
        JSONObject push = details.optJSONObject("push");

        prefs(context)
            .edit()
            .clear()
            .putBoolean("enabled", true)
            .putString("token", details.optString("token", ""))
            .putLong("user_id", details.optLong("user_id", 0))
            .putString("server_url", details.optString("server_url", ""))
            .putString("channel", details.optString("channel", ""))
            .putString("websocket_url", details.isNull("websocket_url") ? "" : details.optString("websocket_url", ""))
            .putString("auth_url", endpoints.optString("auth", ""))
            .putString("feed_url", endpoints.optString("notifications", ""))
            .putString("revoke_url", endpoints.optString("revoke", ""))
            .putString("push_token_url", endpoints.optString("push_token", ""))
            .putString("delivered_url", endpoints.optString("delivered", ""))
            .putString("reply_url", endpoints.optString("reply", ""))
            .putString("read_url", endpoints.optString("read", ""))
            .putBoolean("server_push", push != null && push.optBoolean("fcm", false))
            .putInt("poll_interval", details.optInt("poll_interval_seconds", 60))
            .putBoolean("show_preview", details.optBoolean("show_preview", true))
            .putString("cursor", details.optString("server_time", ""))
            .apply();
    }

    static void setMode(Context context, String mode) {
        prefs(context).edit().putString("mode", mode).apply();
    }

    static void clear(Context context) {
        prefs(context).edit().clear().apply();
    }

    static String cursor(Context context) {
        return prefs(context).getString("cursor", "");
    }

    static void setCursor(Context context, String cursor) {
        if (!TextUtils.isEmpty(cursor)) {
            prefs(context).edit().putString("cursor", cursor).apply();
        }
    }

    /**
     * Remember a notification id; returns false when it was already handled
     * (the same message can arrive by push, WebSocket and polling).
     */
    static synchronized boolean markHandled(Context context, String id) {
        if (TextUtils.isEmpty(id)) {
            return true;
        }

        SharedPreferences prefs = prefs(context);
        String stored = prefs.getString("handled_ids", "");
        LinkedHashSet<String> ids = new LinkedHashSet<>();
        if (!stored.isEmpty()) {
            ids.addAll(Arrays.asList(stored.split(",")));
        }
        if (!ids.add(id)) {
            return false;
        }

        List<String> list = new ArrayList<>(ids);
        if (list.size() > MAX_REMEMBERED_IDS) {
            list = list.subList(list.size() - MAX_REMEMBERED_IDS, list.size());
        }
        prefs.edit().putString("handled_ids", TextUtils.join(",", list)).commit();

        return true;
    }

    private static SharedPreferences prefs(Context context) {
        return context.getApplicationContext().getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }
}
