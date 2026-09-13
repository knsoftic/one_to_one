package com.hunario.chat;

import android.app.Notification;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.ConnectivityManager;
import android.net.Network;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.SystemClock;
import android.util.Log;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.ServiceCompat;
import androidx.core.content.ContextCompat;
import java.io.IOException;
import java.util.concurrent.Executors;
import java.util.concurrent.RejectedExecutionException;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.ScheduledFuture;
import java.util.concurrent.TimeUnit;
import okhttp3.FormBody;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;
import okhttp3.WebSocket;
import okhttp3.WebSocketListener;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * Fallback delivery of new-message notifications for phones where Firebase push is not
 * available (no Google Play services, or push not configured on the server).
 *
 * Runs as a foreground service and keeps a WebSocket (Pusher protocol) open to the
 * server's Reverb instance, subscribed to the user's private channel. While the socket
 * is down it polls GET /api/device/notifications instead, and it always catches up
 * from the last cursor after reconnecting.
 */
public class ChatNotificationService extends Service {

    private static final String TAG = "One2OneNotifications";

    static final String ACTION_START = "com.hunario.chat.action.START_NOTIFICATIONS";
    static final String ACTION_RELOAD = "com.hunario.chat.action.RELOAD_NOTIFICATIONS";

    private static final String NOTIFICATION_EVENT = "Illuminate\\Notifications\\Events\\BroadcastNotificationCreated";
    private static final long MAX_RECONNECT_DELAY_MS = 5 * 60 * 1000L;
    private static final long MIN_SILENCE_BEFORE_RECONNECT_MS = 150 * 1000L;

    private static volatile boolean running = false;

    private final Handler mainHandler = new Handler(Looper.getMainLooper());

    private ScheduledExecutorService executor;
    private OkHttpClient http;
    private ConnectivityManager.NetworkCallback networkCallback;

    // Owned by the executor thread.
    private NotificationSettings settings;
    private WebSocket socket;
    private int socketGeneration = 0;
    private int activityTimeoutSeconds = 30;
    private long lastFrameAt = 0;
    private int reconnectAttempts = 0;
    private ScheduledFuture<?> reconnectTask;
    private ScheduledFuture<?> pingTask;
    private ScheduledFuture<?> pollTask;

    private volatile boolean subscribed = false;
    private boolean connectedNotificationShown = false;

    static boolean isRunning() {
        return running;
    }

    /**
     * Start (or keep) the background connection when notifications are enabled.
     *
     * @return false when Android refused to start it (background start restrictions)
     */
    static boolean start(Context context) {
        return send(context, ACTION_START);
    }

    /** Apply new connection details (e.g. another account signed in). */
    static void reload(Context context) {
        send(context, ACTION_RELOAD);
    }

    static void stop(Context context) {
        context.stopService(new Intent(context, ChatNotificationService.class));
    }

    private static boolean send(Context context, String action) {
        NotificationSettings settings = NotificationSettings.load(context);
        if (!settings.isUsable() || settings.usesPush()) {
            return false; // Firebase push delivers notifications on this phone
        }

        try {
            ContextCompat.startForegroundService(context, new Intent(context, ChatNotificationService.class).setAction(action));
            return true;
        } catch (RuntimeException exception) {
            // Android 12+ refuses to start foreground services from the background
            // unless the app is exempt from battery optimization.
            Log.w(TAG, "Could not start background notifications", exception);
            return false;
        }
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onCreate() {
        super.onCreate();
        MessageNotifier.createChannels(this);

        executor = Executors.newSingleThreadScheduledExecutor();
        http = NotificationFeed.http();

        registerNetworkCallback();
        running = true;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!enterForeground()) {
            stopSelf();
            return START_NOT_STICKY;
        }

        final boolean reload = intent != null && ACTION_RELOAD.equals(intent.getAction());

        post(() -> {
            NotificationSettings latest = NotificationSettings.load(this);
            if (!latest.isUsable() || latest.usesPush()) {
                shutdown();
                return;
            }

            if (reload || settings == null) {
                closeSocket();
                cancel(reconnectTask);
                reconnectAttempts = 0;
            }
            settings = latest;

            if (socket == null && settings.hasWebSocket()) {
                connect();
            }
            // Catch up right away (e.g. the app was just opened), then poll until the socket is ready.
            schedulePoll(reload ? 0 : 1000);
        });

        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        running = false;
        unregisterNetworkCallback();
        if (executor != null) {
            executor.shutdownNow();
        }
        WebSocket current = socket;
        if (current != null) {
            current.cancel();
        }
        super.onDestroy();
    }

    /* ------------------------------------------------------------------ */
    /* Foreground state                                                    */
    /* ------------------------------------------------------------------ */

    private boolean enterForeground() {
        try {
            Notification notification = MessageNotifier.connectionNotification(this, subscribed);
            if (Build.VERSION.SDK_INT >= 34) {
                startForeground(MessageNotifier.CONNECTION_NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE);
            } else {
                startForeground(MessageNotifier.CONNECTION_NOTIFICATION_ID, notification);
            }
            return true;
        } catch (RuntimeException exception) {
            Log.w(TAG, "Foreground service not allowed", exception);
            return false;
        }
    }

    private void updateConnectionNotification() {
        if (connectedNotificationShown == subscribed) {
            return;
        }
        connectedNotificationShown = subscribed;

        try {
            NotificationManagerCompat.from(this).notify(
                MessageNotifier.CONNECTION_NOTIFICATION_ID,
                MessageNotifier.connectionNotification(this, subscribed)
            );
        } catch (SecurityException ignored) {
            // Notifications are switched off; the service keeps running.
        }
    }

    /** Stop everything (called on the executor thread). */
    private void shutdown() {
        cancel(reconnectTask);
        cancel(pingTask);
        cancel(pollTask);
        closeSocket();
        mainHandler.post(() -> {
            ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE);
            stopSelf();
        });
    }

    /** The server no longer accepts this phone's token (signed out, password changed, account suspended). */
    private void signedOut() {
        NotificationFeed.signOut(this);
        shutdown();
    }

    /* ------------------------------------------------------------------ */
    /* WebSocket (Pusher protocol, Laravel Reverb)                         */
    /* ------------------------------------------------------------------ */

    private void connect() {
        cancel(reconnectTask);
        closeSocket();

        final int generation = ++socketGeneration;
        Request request = new Request.Builder()
            .url(settings.websocketUrl)
            .header("Origin", settings.serverUrl)
            .header("User-Agent", "One2OneApp-Android")
            .build();

        socket = http.newWebSocket(
            request,
            new WebSocketListener() {
                @Override
                public void onMessage(WebSocket webSocket, String text) {
                    post(() -> onFrame(generation, text));
                }

                @Override
                public void onClosing(WebSocket webSocket, int code, String reason) {
                    webSocket.close(1000, null);
                }

                @Override
                public void onClosed(WebSocket webSocket, int code, String reason) {
                    post(() -> onDisconnected(generation));
                }

                @Override
                public void onFailure(WebSocket webSocket, Throwable error, Response response) {
                    post(() -> onDisconnected(generation));
                }
            }
        );
    }

    private void onFrame(int generation, String text) {
        if (generation != socketGeneration) {
            return;
        }
        lastFrameAt = SystemClock.elapsedRealtime();

        try {
            JSONObject frame = new JSONObject(text);
            String event = frame.optString("event");

            switch (event) {
                case "pusher:connection_established": {
                    JSONObject data = dataOf(frame);
                    activityTimeoutSeconds = Math.max(10, Math.min(120, data.optInt("activity_timeout", 30)));
                    subscribe(generation, data.getString("socket_id"));
                    break;
                }
                case "pusher_internal:subscription_succeeded":
                    subscribed = true;
                    reconnectAttempts = 0;
                    cancel(pollTask);
                    updateConnectionNotification();
                    schedulePing();
                    fetchFeed(); // anything that arrived while disconnected
                    break;
                case "pusher:ping":
                    sendFrame("pusher:pong");
                    break;
                case "pusher:error":
                    Log.w(TAG, "Realtime error: " + frame.optString("data"));
                    break;
                case NOTIFICATION_EVENT:
                    NotificationFeed.presentRealtime(this, settings, dataOf(frame));
                    break;
                default:
                    break;
            }
        } catch (JSONException exception) {
            Log.w(TAG, "Unexpected realtime frame", exception);
        }
    }

    private void subscribe(int generation, String socketId) {
        Request request = NotificationFeed.authorized(settings, settings.authUrl)
            .post(new FormBody.Builder().add("socket_id", socketId).add("channel_name", settings.channel).build())
            .build();

        try (Response response = http.newCall(request).execute()) {
            if (generation != socketGeneration) {
                return;
            }
            if (response.code() == 401) {
                signedOut();
                return;
            }

            ResponseBody body = response.body();
            if (!response.isSuccessful() || body == null) {
                Log.w(TAG, "Channel authorization failed: HTTP " + response.code());
                dropSocket();
                return;
            }

            JSONObject data = new JSONObject().put("auth", new JSONObject(body.string()).getString("auth")).put("channel", settings.channel);
            WebSocket current = socket;
            if (current != null) {
                current.send(new JSONObject().put("event", "pusher:subscribe").put("data", data).toString());
            }
        } catch (IOException | JSONException exception) {
            Log.w(TAG, "Channel authorization failed", exception);
            dropSocket();
        }
    }

    private void onDisconnected(int generation) {
        if (generation != socketGeneration) {
            return;
        }

        socket = null;
        subscribed = false;
        cancel(pingTask);
        updateConnectionNotification();

        // Poll while the socket is down and try to reconnect with backoff.
        schedulePoll(5000);
        scheduleReconnect();
    }

    private void scheduleReconnect() {
        if (settings == null || !settings.hasWebSocket()) {
            return;
        }

        cancel(reconnectTask);
        long delay = Math.min(MAX_RECONNECT_DELAY_MS, 1000L << Math.min(reconnectAttempts, 9));
        reconnectAttempts++;
        reconnectTask = executor.schedule(this::connect, delay, TimeUnit.MILLISECONDS);
    }

    private void schedulePing() {
        cancel(pingTask);
        final long period = activityTimeoutSeconds * 1000L;

        pingTask = executor.scheduleWithFixedDelay(
            () -> {
                if (socket == null) {
                    return;
                }
                long silence = SystemClock.elapsedRealtime() - lastFrameAt;
                if (silence > Math.max(period * 3, MIN_SILENCE_BEFORE_RECONNECT_MS)) {
                    dropSocket(); // connection is dead; onFailure reconnects
                    return;
                }
                sendFrame("pusher:ping");
            },
            period,
            period,
            TimeUnit.MILLISECONDS
        );
    }

    private void sendFrame(String event) {
        WebSocket current = socket;
        if (current == null) {
            return;
        }
        try {
            current.send(new JSONObject().put("event", event).put("data", new JSONObject()).toString());
        } catch (JSONException ignored) {
            // Static payload.
        }
    }

    private void dropSocket() {
        WebSocket current = socket;
        if (current != null) {
            current.cancel();
        }
    }

    private void closeSocket() {
        WebSocket current = socket;
        socket = null;
        subscribed = false;
        socketGeneration++;
        cancel(pingTask);
        if (current != null) {
            current.cancel();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Polling feed                                                        */
    /* ------------------------------------------------------------------ */

    private void schedulePoll(long delayMs) {
        cancel(pollTask);
        pollTask = executor.schedule(
            () -> {
                fetchFeed();
                if (!subscribed && settings != null && settings.isUsable()) {
                    schedulePoll(settings.pollIntervalSeconds * 1000L);
                }
            },
            delayMs,
            TimeUnit.MILLISECONDS
        );
    }

    private void fetchFeed() {
        if (settings != null && NotificationFeed.fetch(this, settings) == NotificationFeed.Result.UNAUTHORIZED) {
            signedOut();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private static JSONObject dataOf(JSONObject frame) throws JSONException {
        Object data = frame.opt("data");
        if (data instanceof JSONObject) {
            return (JSONObject) data;
        }
        if (data instanceof String) {
            return new JSONObject((String) data);
        }
        return new JSONObject();
    }

    private void post(Runnable task) {
        try {
            if (executor != null && !executor.isShutdown()) {
                executor.execute(task);
            }
        } catch (RejectedExecutionException ignored) {
            // Service is stopping.
        }
    }

    private static void cancel(ScheduledFuture<?> task) {
        if (task != null) {
            task.cancel(false);
        }
    }

    private void registerNetworkCallback() {
        ConnectivityManager connectivity = ContextCompat.getSystemService(this, ConnectivityManager.class);
        if (connectivity == null) {
            return;
        }

        networkCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(Network network) {
                post(() -> {
                    if (settings == null || !settings.isUsable()) {
                        return;
                    }
                    reconnectAttempts = 0;
                    if (socket == null && settings.hasWebSocket()) {
                        connect();
                    }
                    if (!subscribed) {
                        schedulePoll(2000);
                    }
                });
            }
        };

        try {
            connectivity.registerDefaultNetworkCallback(networkCallback);
        } catch (RuntimeException exception) {
            networkCallback = null;
        }
    }

    private void unregisterNetworkCallback() {
        ConnectivityManager connectivity = ContextCompat.getSystemService(this, ConnectivityManager.class);
        if (connectivity != null && networkCallback != null) {
            try {
                connectivity.unregisterNetworkCallback(networkCallback);
            } catch (RuntimeException ignored) {
                // Already unregistered.
            }
        }
        networkCallback = null;
    }
}
