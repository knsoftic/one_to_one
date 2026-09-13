package com.hunario.chat;

import android.content.Context;
import android.util.Log;
import java.io.IOException;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.concurrent.TimeUnit;
import okhttp3.HttpUrl;
import okhttp3.MediaType;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.ResponseBody;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * Server calls shared by the push receiver, the fallback background connection and
 * the notification actions: polling feed, delivered receipts and presenting messages.
 */
final class NotificationFeed {

    enum Result {
        OK,
        UNAUTHORIZED,
        FAILED,
    }

    private static final String TAG = "One2OneNotifications";
    private static final String NOTIFICATION_TYPE = "message.notification";
    static final MediaType JSON = MediaType.get("application/json; charset=utf-8");

    private static volatile OkHttpClient client;

    private NotificationFeed() {}

    static OkHttpClient http() {
        if (client == null) {
            synchronized (NotificationFeed.class) {
                if (client == null) {
                    client = new OkHttpClient.Builder()
                        .connectTimeout(15, TimeUnit.SECONDS)
                        .readTimeout(20, TimeUnit.SECONDS)
                        .writeTimeout(15, TimeUnit.SECONDS)
                        .retryOnConnectionFailure(true)
                        .build();
                }
            }
        }
        return client;
    }

    static Request.Builder authorized(NotificationSettings settings, String url) {
        return new Request.Builder()
            .url(url)
            .header("Authorization", "Bearer " + settings.token)
            .header("Accept", "application/json")
            .header("User-Agent", "One2OneApp-Android");
    }

    /** POST a JSON body with the device token. Returns the HTTP status, or 0 on network failure. */
    static int postJson(NotificationSettings settings, String url, JSONObject body) {
        if (url == null || url.isEmpty()) {
            return 0;
        }
        Request request = authorized(settings, url).post(RequestBody.create(body.toString(), JSON)).build();
        try (Response response = http().newCall(request).execute()) {
            return response.code();
        } catch (IOException exception) {
            return 0;
        }
    }

    /** Fetch unread message notifications since the stored cursor and show the new ones. */
    static Result fetch(Context context, NotificationSettings settings) {
        if (!settings.isUsable()) {
            return Result.FAILED;
        }

        HttpUrl base = HttpUrl.parse(settings.feedUrl);
        if (base == null) {
            return Result.FAILED;
        }

        HttpUrl.Builder url = base.newBuilder();
        String cursor = NotificationSettings.cursor(context);
        if (!cursor.isEmpty()) {
            url.addQueryParameter("after", cursor);
        }

        try (Response response = http().newCall(authorized(settings, url.build().toString()).get().build()).execute()) {
            if (response.code() == 401) {
                return Result.UNAUTHORIZED;
            }

            ResponseBody body = response.body();
            if (!response.isSuccessful() || body == null) {
                return Result.FAILED;
            }

            JSONObject json = new JSONObject(body.string());
            List<Long> delivered = new ArrayList<>();

            JSONArray items = json.optJSONArray("data");
            if (items != null) {
                for (int i = 0; i < items.length(); i++) {
                    JSONObject item = items.getJSONObject(i);
                    if (NOTIFICATION_TYPE.equals(item.optString("type"))) {
                        ChatNotification message = ChatNotification.fromJson(item);
                        present(context, settings, message);
                        delivered.add(message.messageId);
                    }
                }
            }

            JSONArray unread = json.optJSONArray("unread_conversation_ids");
            if (unread != null) {
                Set<Long> ids = new HashSet<>();
                for (int i = 0; i < unread.length(); i++) {
                    ids.add(unread.getLong(i));
                }
                MessageNotifier.retainOnly(context, ids);
            }

            NotificationSettings.setCursor(context, json.optString("server_time", ""));
            markDelivered(settings, delivered);
            return Result.OK;
        } catch (IOException | JSONException exception) {
            Log.d(TAG, "Notification check failed: " + exception.getMessage());
            return Result.FAILED;
        }
    }

    /** Realtime event from the fallback WebSocket connection. */
    static void presentRealtime(Context context, NotificationSettings settings, JSONObject payload) {
        if (!NOTIFICATION_TYPE.equals(payload.optString("type"))) {
            return;
        }
        ChatNotification message = ChatNotification.fromJson(payload);
        present(context, settings, message);
        List<Long> ids = new ArrayList<>();
        ids.add(message.messageId);
        markDelivered(settings, ids);
    }

    /** Show one new message unless it was shown already or its chat is open on screen. */
    static void present(Context context, NotificationSettings settings, ChatNotification message) {
        if (message.conversationId <= 0 || !NotificationSettings.markHandled(context, message.id)) {
            return; // already shown (push, WebSocket and polling can all deliver it)
        }
        if (MainActivity.isViewingConversation(message.conversationId)) {
            return;
        }
        MessageNotifier.showMessage(context, settings, message);
    }

    /** The messages reached this phone: ✓✓ for the sender, like WhatsApp. */
    static void markDelivered(NotificationSettings settings, List<Long> messageIds) {
        JSONArray ids = new JSONArray();
        for (Long id : messageIds) {
            if (id != null && id > 0) {
                ids.put(id);
            }
        }
        if (ids.length() == 0 || settings.deliveredUrl.isEmpty()) {
            return;
        }
        try {
            postJson(settings, settings.deliveredUrl, new JSONObject().put("ids", ids));
        } catch (JSONException ignored) {
            // Static payload.
        }
    }

    /** The server rejected the token: this phone is signed out of notifications. */
    static void signOut(Context context) {
        NotificationSettings.clear(context);
        KeepAliveReceiver.cancel(context);
        MessageNotifier.clearAll(context);
    }

    static String text(JSONObject object, String key) {
        if (object == null || object.isNull(key)) {
            return "";
        }
        return object.optString(key, "").trim();
    }
}
