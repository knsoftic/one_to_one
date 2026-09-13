package com.hunario.chat;

import android.content.Intent;
import java.text.ParseException;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.Map;
import org.json.JSONObject;

/**
 * A call ringing on this phone, from a Firebase push or the fallback WebSocket.
 */
final class IncomingCall {

    private static final String EXTRA_PREFIX = "com.hunario.chat.call.";
    private static final int DEFAULT_TIMEOUT_SECONDS = 45;

    final long callId;
    final long conversationId;
    final long callerId;
    final String type;
    final String callerName;
    final String avatarUrl;
    final String initials;
    final int avatarHue;
    final long startedAt;
    final int timeoutSeconds;

    private IncomingCall(
        long callId,
        long conversationId,
        long callerId,
        String type,
        String callerName,
        String avatarUrl,
        String initials,
        int avatarHue,
        long startedAt,
        int timeoutSeconds
    ) {
        this.callId = callId;
        this.conversationId = conversationId;
        this.callerId = callerId;
        this.type = "video".equals(type) ? "video" : "audio";
        this.callerName = callerName == null ? "" : callerName;
        this.avatarUrl = avatarUrl == null ? "" : avatarUrl;
        this.initials = initials == null ? "" : initials;
        this.avatarHue = avatarHue;
        this.startedAt = startedAt > 0 ? startedAt : System.currentTimeMillis();
        this.timeoutSeconds = timeoutSeconds > 0 ? timeoutSeconds : DEFAULT_TIMEOUT_SECONDS;
    }

    boolean isVideo() {
        return "video".equals(type);
    }

    boolean isValid() {
        return callId > 0 && conversationId > 0;
    }

    long expiresAt() {
        return startedAt + timeoutSeconds * 1000L;
    }

    boolean isExpired() {
        return System.currentTimeMillis() >= expiresAt();
    }

    /** Firebase data message (all values are strings). */
    static IncomingCall fromPush(Map<String, String> data) {
        return new IncomingCall(
            number(data.get("call_id")),
            number(data.get("conversation_id")),
            number(data.get("caller_id")),
            data.get("call_type"),
            data.get("caller_name"),
            data.get("avatar_url"),
            data.get("initials"),
            (int) number(data.get("avatar_hue")),
            number(data.get("started_at")),
            (int) number(data.get("timeout_seconds"))
        );
    }

    /** "call.incoming" event from the fallback WebSocket (CallResource payload). */
    static IncomingCall fromJson(JSONObject call, int timeoutSeconds) {
        JSONObject caller = call.optJSONObject("caller");
        return new IncomingCall(
            call.optLong("id", 0),
            call.optLong("conversation_id", 0),
            call.optLong("caller_id", 0),
            call.optString("type", "audio"),
            caller == null ? "" : caller.optString("name", ""),
            caller == null || caller.isNull("avatar_url") ? "" : caller.optString("avatar_url", ""),
            caller == null ? "" : caller.optString("initials", ""),
            caller == null ? 0 : caller.optInt("avatar_hue", 0),
            parseIso(call.optString("created_at", "")),
            timeoutSeconds
        );
    }

    Intent putInto(Intent intent) {
        return intent
            .putExtra(EXTRA_PREFIX + "id", callId)
            .putExtra(EXTRA_PREFIX + "conversation", conversationId)
            .putExtra(EXTRA_PREFIX + "caller", callerId)
            .putExtra(EXTRA_PREFIX + "type", type)
            .putExtra(EXTRA_PREFIX + "name", callerName)
            .putExtra(EXTRA_PREFIX + "avatar", avatarUrl)
            .putExtra(EXTRA_PREFIX + "initials", initials)
            .putExtra(EXTRA_PREFIX + "hue", avatarHue)
            .putExtra(EXTRA_PREFIX + "started", startedAt)
            .putExtra(EXTRA_PREFIX + "timeout", timeoutSeconds);
    }

    static IncomingCall from(Intent intent) {
        if (intent == null || intent.getLongExtra(EXTRA_PREFIX + "id", 0) <= 0) {
            return null;
        }
        return new IncomingCall(
            intent.getLongExtra(EXTRA_PREFIX + "id", 0),
            intent.getLongExtra(EXTRA_PREFIX + "conversation", 0),
            intent.getLongExtra(EXTRA_PREFIX + "caller", 0),
            intent.getStringExtra(EXTRA_PREFIX + "type"),
            intent.getStringExtra(EXTRA_PREFIX + "name"),
            intent.getStringExtra(EXTRA_PREFIX + "avatar"),
            intent.getStringExtra(EXTRA_PREFIX + "initials"),
            intent.getIntExtra(EXTRA_PREFIX + "hue", 0),
            intent.getLongExtra(EXTRA_PREFIX + "started", 0),
            intent.getIntExtra(EXTRA_PREFIX + "timeout", DEFAULT_TIMEOUT_SECONDS)
        );
    }

    /** Chat URL that opens the call in the web app and answers it right away when $answer. */
    String appUrl(String serverUrl, boolean answer) {
        return serverUrl + "/chat/" + conversationId + "?call=" + callId + (answer ? "&answer=1" : "");
    }

    private static long number(String value) {
        if (value == null) {
            return 0;
        }
        try {
            return Long.parseLong(value.trim());
        } catch (NumberFormatException exception) {
            return 0;
        }
    }

    private static long parseIso(String value) {
        if (value == null || value.isEmpty()) {
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
