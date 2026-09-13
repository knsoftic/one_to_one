package com.hunario.chat;

import android.content.Context;
import android.util.Log;
import com.google.firebase.FirebaseApp;
import com.google.firebase.messaging.FirebaseMessaging;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * Chooses how notifications reach this phone:
 *  - Firebase push when this build has Firebase, Google Play services work and the
 *    server has push configured (no background service, like WhatsApp);
 *  - otherwise the app's own background connection.
 */
final class PushRegistrar {

    private static final String TAG = "One2OnePush";
    private static final ExecutorService executor = Executors.newSingleThreadExecutor();

    private PushRegistrar() {}

    /** Called when the app starts and after notifications are enabled. */
    static void register(Context context) {
        final Context app = context.getApplicationContext();
        NotificationSettings settings = NotificationSettings.load(app);
        if (!settings.isUsable()) {
            return;
        }

        if (!settings.serverPush || settings.pushTokenUrl.isEmpty() || !firebaseReady(app)) {
            useBackgroundConnection(app);
            return;
        }

        try {
            FirebaseMessaging.getInstance()
                .getToken()
                .addOnCompleteListener((task) ->
                    executor.execute(() -> {
                        if (task.isSuccessful() && task.getResult() != null) {
                            uploadToken(app, task.getResult());
                        } else {
                            Log.w(TAG, "Firebase token unavailable", task.getException());
                            fallBackIfPushNeverWorked(app);
                        }
                    })
                );
        } catch (RuntimeException exception) {
            Log.w(TAG, "Firebase unavailable", exception);
            useBackgroundConnection(app);
        }
    }

    /** Send the Firebase token to the server. Call off the main thread. */
    static void uploadToken(Context context, String fcmToken) {
        Context app = context.getApplicationContext();
        NotificationSettings settings = NotificationSettings.load(app);
        if (!settings.isUsable() || !settings.serverPush || settings.pushTokenUrl.isEmpty()) {
            return;
        }

        int status;
        try {
            status = putToken(settings, fcmToken);
        } catch (JSONException exception) {
            return;
        }

        if (status == 401) {
            NotificationFeed.signOut(app);
            ChatNotificationService.stop(app);
        } else if (status >= 200 && status < 300) {
            usePush(app);
        } else {
            fallBackIfPushNeverWorked(app);
        }
    }

    private static int putToken(NotificationSettings settings, String fcmToken) throws JSONException {
        okhttp3.Request request = NotificationFeed.authorized(settings, settings.pushTokenUrl)
            .put(okhttp3.RequestBody.create(new JSONObject().put("token", fcmToken).toString(), NotificationFeed.JSON))
            .build();
        try (okhttp3.Response response = NotificationFeed.http().newCall(request).execute()) {
            return response.code();
        } catch (java.io.IOException exception) {
            return 0;
        }
    }

    private static boolean firebaseReady(Context context) {
        try {
            return !FirebaseApp.getApps(context).isEmpty();
        } catch (RuntimeException exception) {
            return false;
        }
    }

    private static void usePush(Context app) {
        NotificationSettings.setMode(app, NotificationSettings.MODE_PUSH);
        KeepAliveReceiver.cancel(app);
        ChatNotificationService.stop(app);
    }

    /** A temporary failure (e.g. no internet) must not undo push that already worked. */
    private static void fallBackIfPushNeverWorked(Context app) {
        if (!NotificationSettings.load(app).usesPush()) {
            useBackgroundConnection(app);
        }
    }

    private static void useBackgroundConnection(Context app) {
        NotificationSettings.setMode(app, NotificationSettings.MODE_SOCKET);
        ChatNotificationService.start(app);
        KeepAliveReceiver.schedule(app);
    }
}
