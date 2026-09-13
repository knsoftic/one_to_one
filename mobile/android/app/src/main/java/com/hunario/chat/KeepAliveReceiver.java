package com.hunario.chat;

import android.app.AlarmManager;
import android.app.PendingIntent;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.SystemClock;

/**
 * Safety net for phones that kill background apps: about every 15 minutes it restarts the
 * notification service, or — when Android doesn't allow that — checks for new messages once.
 */
public class KeepAliveReceiver extends BroadcastReceiver {

    static final String ACTION_KEEP_ALIVE = "com.hunario.chat.action.KEEP_ALIVE";

    private static final long INTERVAL_MS = AlarmManager.INTERVAL_FIFTEEN_MINUTES;

    static void schedule(Context context) {
        NotificationSettings settings = NotificationSettings.load(context);
        if (!settings.isUsable() || settings.usesPush()) {
            return; // Firebase push needs no safety check
        }

        AlarmManager alarms = context.getSystemService(AlarmManager.class);
        if (alarms != null) {
            alarms.setInexactRepeating(
                AlarmManager.ELAPSED_REALTIME_WAKEUP,
                SystemClock.elapsedRealtime() + INTERVAL_MS,
                INTERVAL_MS,
                pendingIntent(context)
            );
        }
    }

    static void cancel(Context context) {
        AlarmManager alarms = context.getSystemService(AlarmManager.class);
        if (alarms != null) {
            alarms.cancel(pendingIntent(context));
        }
    }

    private static PendingIntent pendingIntent(Context context) {
        Intent intent = new Intent(context, KeepAliveReceiver.class).setAction(ACTION_KEEP_ALIVE);
        return PendingIntent.getBroadcast(context, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        final Context app = context.getApplicationContext();
        final NotificationSettings settings = NotificationSettings.load(app);

        if (!settings.isUsable() || settings.usesPush()) {
            cancel(app);
            return;
        }

        if (ChatNotificationService.isRunning() || ChatNotificationService.start(app)) {
            return;
        }

        // The service could not be restarted from the background: check once now.
        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                if (NotificationFeed.fetch(app, settings) == NotificationFeed.Result.UNAUTHORIZED) {
                    NotificationFeed.signOut(app);
                }
            } finally {
                pending.finish();
            }
        }, "one2one-keep-alive").start();
    }
}
