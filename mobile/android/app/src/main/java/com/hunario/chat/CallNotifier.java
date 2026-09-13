package com.hunario.chat;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.media.AudioAttributes;
import android.media.AudioManager;
import android.media.RingtoneManager;
import android.os.Build;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.Person;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.drawable.IconCompat;
import org.json.JSONObject;

/**
 * WhatsApp-style incoming call: a ringing, full-screen notification (over the lock
 * screen when allowed) with Answer and Decline, even while the app is closed.
 */
final class CallNotifier {

    static final String CHANNEL_CALLS = "incoming_calls";
    private static final String TAG_CALL = "call";
    private static final long[] VIBRATION = { 0, 900, 900, 900, 900 };

    private CallNotifier() {}

    static void createChannel(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null || manager.getNotificationChannel(CHANNEL_CALLS) != null) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
            CHANNEL_CALLS,
            context.getString(R.string.channel_calls),
            NotificationManager.IMPORTANCE_HIGH
        );
        channel.setDescription(context.getString(R.string.channel_calls_description));
        channel.setSound(
            RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
            new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build()
        );
        channel.enableVibration(true);
        channel.setVibrationPattern(VIBRATION);
        channel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        manager.createNotificationChannel(channel);
    }

    /**
     * Ring for a call. Call off the main thread (loads the caller's photo).
     */
    static void showIncoming(Context context, NotificationSettings settings, IncomingCall call) {
        if (!call.isValid() || call.isExpired() || !settings.isUsable()) {
            return;
        }
        // The same call can arrive by push and by WebSocket.
        if (!NotificationSettings.markHandled(context, "call-" + call.callId)) {
            return;
        }

        createChannel(context);

        String name = call.callerName.isEmpty() ? context.getString(R.string.app_name) : call.callerName;
        String text = context.getString(call.isVideo() ? R.string.incoming_video_call : R.string.incoming_voice_call);
        Bitmap avatar = AvatarLoader.load(context, settings, call.avatarUrl, call.initials, call.avatarHue);

        Person caller = new Person.Builder()
            .setKey("user-" + call.callerId)
            .setName(name)
            .setIcon(IconCompat.createWithBitmap(avatar))
            .setImportant(true)
            .build();

        PendingIntent fullScreen = PendingIntent.getActivity(
            context,
            requestCode(call.callId, 0),
            call.putInto(new Intent(context, IncomingCallActivity.class)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_NO_USER_ACTION),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );
        PendingIntent answer = answerIntent(context, settings, call);
        PendingIntent decline = CallActionReceiver.declineIntent(context, call.callId);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_CALLS)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(context, R.color.brand))
            .setContentTitle(name)
            .setContentText(text)
            .setLargeIcon(avatar)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            .setOngoing(true)
            .setAutoCancel(false)
            .setShowWhen(false)
            .setTimeoutAfter(Math.max(1000, call.expiresAt() - System.currentTimeMillis()))
            .setContentIntent(fullScreen)
            .setFullScreenIntent(fullScreen, true)
            .setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE), AudioManager.STREAM_RING)
            .setVibrate(VIBRATION);

        if (canUseFullScreenIntent(context)) {
            builder.setStyle(NotificationCompat.CallStyle.forIncomingCall(caller, decline, answer).setIsVideo(call.isVideo()));
        } else {
            // Without full-screen permission Android rejects the call style: plain buttons instead.
            builder
                .addAction(new NotificationCompat.Action(R.drawable.ic_call_end, context.getString(R.string.call_decline), decline))
                .addAction(new NotificationCompat.Action(call.isVideo() ? R.drawable.ic_videocam : R.drawable.ic_call, context.getString(R.string.call_answer), answer));
        }

        Notification notification = builder.build();
        notification.flags |= Notification.FLAG_INSISTENT; // keep ringing until answered or dismissed

        try {
            NotificationManagerCompat.from(context).notify(TAG_CALL, (int) call.callId, notification);
        } catch (SecurityException ignored) {
            return; // notifications are off
        }

        // "Ringing…" on the caller's screen.
        NotificationFeed.postJson(settings, NotificationSettings.forConversation(settings.callRingingUrl, call.callId), new JSONObject());
    }

    /** Stop ringing for a call (answered, declined, cancelled, answered on another device). */
    static void cancelIncoming(Context context, long callId) {
        NotificationManagerCompat.from(context).cancel(TAG_CALL, (int) callId);
        IncomingCallActivity.dismiss(callId);
    }

    /** Opens the app on the chat and answers the call there. */
    static PendingIntent answerIntent(Context context, NotificationSettings settings, IncomingCall call) {
        return PendingIntent.getActivity(
            context,
            requestCode(call.callId, 1),
            answerActivityIntent(context, settings, call),
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );
    }

    static Intent answerActivityIntent(Context context, NotificationSettings settings, IncomingCall call) {
        return new Intent(context, MainActivity.class)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP)
            .putExtra(MainActivity.EXTRA_OPEN_URL, call.appUrl(settings.serverUrl, true))
            .putExtra(MainActivity.EXTRA_CONVERSATION_ID, call.conversationId)
            .putExtra(MainActivity.EXTRA_CALL_ID, call.callId)
            .putExtra(MainActivity.EXTRA_CALL_ANSWER, true);
    }

    static boolean canUseFullScreenIntent(Context context) {
        if (Build.VERSION.SDK_INT < 34) {
            return true;
        }
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        return manager != null && manager.canUseFullScreenIntent();
    }

    private static int requestCode(long callId, int action) {
        return (int) (callId * 3 + action) | 0x40000000;
    }
}
