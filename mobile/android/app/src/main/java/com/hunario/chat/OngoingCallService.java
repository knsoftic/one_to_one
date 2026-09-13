package com.hunario.chat;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.content.pm.ServiceInfo;
import android.media.AudioDeviceInfo;
import android.media.AudioManager;
import android.os.Build;
import android.os.IBinder;
import android.os.PowerManager;
import android.util.Log;
import androidx.core.app.NotificationCompat;
import androidx.core.app.Person;
import androidx.core.app.ServiceCompat;
import androidx.core.content.ContextCompat;
import java.util.Arrays;
import java.util.List;

/**
 * Keeps a call going while the app is in the background or the screen is off:
 * foreground service with microphone (and camera) access, the "Ongoing call"
 * notification with Hang up, speaker / earpiece routing and the proximity sensor
 * that turns the screen off at your ear during voice calls.
 */
public class OngoingCallService extends Service {

    private static final String TAG = "One2OneCall";
    private static final String CHANNEL_ONGOING = "ongoing_calls";
    private static final int NOTIFICATION_ID = 1003;

    private static final String ACTION_START = "com.hunario.chat.action.CALL_START";
    private static final String ACTION_UPDATE = "com.hunario.chat.action.CALL_UPDATE";
    private static final String ACTION_REFRESH = "com.hunario.chat.action.CALL_REFRESH";
    private static final String EXTRA_CALL_ID = "call_id";
    private static final String EXTRA_CONVERSATION_ID = "conversation_id";
    private static final String EXTRA_VIDEO = "video";
    private static final String EXTRA_NAME = "name";
    private static final String EXTRA_CONNECTED_AT = "connected_at";
    private static final String EXTRA_SPEAKER = "speaker";

    private static volatile boolean running = false;
    private static volatile boolean speakerOn = false;

    private long callId;
    private long conversationId;
    private boolean video;
    private String name = "";
    private long connectedAt;
    private int previousAudioMode = AudioManager.MODE_NORMAL;
    private PowerManager.WakeLock proximityLock;

    static boolean isRunning() {
        return running;
    }

    static void start(Context context, long callId, long conversationId, boolean video, String name, boolean speaker) {
        Intent intent = new Intent(context, OngoingCallService.class)
            .setAction(ACTION_START)
            .putExtra(EXTRA_CALL_ID, callId)
            .putExtra(EXTRA_CONVERSATION_ID, conversationId)
            .putExtra(EXTRA_VIDEO, video)
            .putExtra(EXTRA_NAME, name)
            .putExtra(EXTRA_SPEAKER, speaker);
        try {
            ContextCompat.startForegroundService(context, intent);
        } catch (RuntimeException exception) {
            Log.w(TAG, "Could not start the call service", exception);
        }
    }

    static void markConnected(Context context, long connectedAt) {
        if (!running) {
            return;
        }
        try {
            context.startService(new Intent(context, OngoingCallService.class).setAction(ACTION_UPDATE).putExtra(EXTRA_CONNECTED_AT, connectedAt));
        } catch (RuntimeException exception) {
            Log.w(TAG, "Could not update the call service", exception);
        }
    }

    static void stop(Context context) {
        context.stopService(new Intent(context, OngoingCallService.class));
    }

    /** Loudspeaker on/off (off = earpiece, or the connected headset). */
    static void setSpeaker(Context context, boolean on) {
        speakerOn = on;
        routeAudio(context.getApplicationContext(), on);
        if (running) {
            try {
                // Re-evaluate the proximity sensor.
                context.startService(new Intent(context, OngoingCallService.class).setAction(ACTION_REFRESH));
            } catch (RuntimeException ignored) {
                // Service is stopping.
            }
        }
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent == null ? null : intent.getAction();

        if (ACTION_START.equals(action)) {
            long newCallId = intent.getLongExtra(EXTRA_CALL_ID, 0);
            boolean sameCall = running && newCallId == callId && newCallId > 0;

            callId = newCallId;
            conversationId = intent.getLongExtra(EXTRA_CONVERSATION_ID, 0);
            video = intent.getBooleanExtra(EXTRA_VIDEO, false);
            name = intent.getStringExtra(EXTRA_NAME) == null ? "" : intent.getStringExtra(EXTRA_NAME);
            if (!sameCall) {
                connectedAt = 0;
            }

            if (!enterForeground()) {
                stopSelf();
                return START_NOT_STICKY;
            }

            if (!sameCall) {
                AudioManager audio = getSystemService(AudioManager.class);
                if (audio != null) {
                    previousAudioMode = audio.getMode();
                    audio.setMode(AudioManager.MODE_IN_COMMUNICATION);
                }
                setSpeaker(this, intent.getBooleanExtra(EXTRA_SPEAKER, video));
            }
            running = true;
            updateProximityLock();
        } else if (ACTION_UPDATE.equals(action) && running) {
            connectedAt = intent.getLongExtra(EXTRA_CONNECTED_AT, System.currentTimeMillis());
            enterForeground();
            // Some devices reset the route when the audio starts flowing.
            routeAudio(this, speakerOn);
            updateProximityLock();
        } else if (ACTION_REFRESH.equals(action) && running) {
            updateProximityLock();
        } else if (!running) {
            stopSelf();
        }

        return START_NOT_STICKY;
    }

    @Override
    public void onDestroy() {
        running = false;
        releaseProximityLock();

        AudioManager audio = getSystemService(AudioManager.class);
        if (audio != null) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                audio.clearCommunicationDevice();
            } else {
                audio.setSpeakerphoneOn(false);
            }
            audio.setMode(previousAudioMode == AudioManager.MODE_IN_COMMUNICATION ? AudioManager.MODE_NORMAL : previousAudioMode);
        }
        speakerOn = false;
        super.onDestroy();
    }

    @Override
    public void onTaskRemoved(Intent rootIntent) {
        // The app was swiped away: the call cannot continue without the web view.
        CallActionReceiver.endCall(this, callId);
        stopSelf();
        super.onTaskRemoved(rootIntent);
    }

    /* ------------------------------------------------------------------ */
    /* Notification                                                        */
    /* ------------------------------------------------------------------ */

    private boolean enterForeground() {
        createChannel();

        int types = ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE;
        if (video && ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
            types |= ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA;
        }

        try {
            ServiceCompat.startForeground(this, NOTIFICATION_ID, buildNotification(), types);
            return true;
        } catch (RuntimeException exception) {
            Log.w(TAG, "Call service not allowed", exception);
            return false;
        }
    }

    private Notification buildNotification() {
        Person person = new Person.Builder().setName(name.isEmpty() ? getString(R.string.app_name) : name).setImportant(true).build();

        Intent open = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent content = PendingIntent.getActivity(this, 0x10000003, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CHANNEL_ONGOING)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(this, R.color.brand))
            .setContentTitle(name.isEmpty() ? getString(R.string.app_name) : name)
            .setContentText(getString(connectedAt > 0 ? (video ? R.string.ongoing_video_call : R.string.ongoing_voice_call) : R.string.call_connecting))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setOngoing(true)
            .setSilent(true)
            .setContentIntent(content)
            .setStyle(NotificationCompat.CallStyle.forOngoingCall(person, CallActionReceiver.hangUpIntent(this, callId)).setIsVideo(video));

        if (connectedAt > 0) {
            builder.setWhen(connectedAt).setUsesChronometer(true).setShowWhen(true);
        }

        return builder.build();
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null || manager.getNotificationChannel(CHANNEL_ONGOING) != null) {
            return;
        }
        NotificationChannel channel = new NotificationChannel(CHANNEL_ONGOING, getString(R.string.channel_ongoing_calls), NotificationManager.IMPORTANCE_DEFAULT);
        channel.setSound(null, null);
        channel.enableVibration(false);
        channel.setShowBadge(false);
        manager.createNotificationChannel(channel);
    }

    /* ------------------------------------------------------------------ */
    /* Audio route & proximity                                             */
    /* ------------------------------------------------------------------ */

    private static void routeAudio(Context context, boolean speaker) {
        AudioManager audio = context.getSystemService(AudioManager.class);
        if (audio == null) {
            return;
        }

        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                List<AudioDeviceInfo> devices = audio.getAvailableCommunicationDevices();
                AudioDeviceInfo target = null;

                if (speaker) {
                    target = find(devices, AudioDeviceInfo.TYPE_BUILTIN_SPEAKER);
                } else {
                    // A headset wins over the earpiece, like any phone call.
                    for (int type : Arrays.asList(
                        AudioDeviceInfo.TYPE_BLUETOOTH_SCO,
                        AudioDeviceInfo.TYPE_BLE_HEADSET,
                        AudioDeviceInfo.TYPE_WIRED_HEADSET,
                        AudioDeviceInfo.TYPE_USB_HEADSET,
                        AudioDeviceInfo.TYPE_BUILTIN_EARPIECE
                    )) {
                        target = find(devices, type);
                        if (target != null) {
                            break;
                        }
                    }
                }

                if (target != null) {
                    audio.setCommunicationDevice(target);
                } else {
                    audio.clearCommunicationDevice();
                }
            } else {
                audio.setSpeakerphoneOn(speaker);
            }
        } catch (RuntimeException exception) {
            Log.w(TAG, "Could not change the audio route", exception);
        }
    }

    private static AudioDeviceInfo find(List<AudioDeviceInfo> devices, int type) {
        for (AudioDeviceInfo device : devices) {
            if (device.getType() == type) {
                return device;
            }
        }
        return null;
    }

    /** Screen off at your ear: voice calls through the earpiece only. */
    private void updateProximityLock() {
        boolean wanted = !video && !speakerOn && connectedAt > 0;

        if (!wanted) {
            releaseProximityLock();
            return;
        }
        if (proximityLock != null && proximityLock.isHeld()) {
            return;
        }

        PowerManager power = getSystemService(PowerManager.class);
        if (power != null && power.isWakeLockLevelSupported(PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK)) {
            proximityLock = power.newWakeLock(PowerManager.PROXIMITY_SCREEN_OFF_WAKE_LOCK, "One2One:call-proximity");
            proximityLock.setReferenceCounted(false);
            proximityLock.acquire(4 * 60 * 60 * 1000L);
        }
    }

    private void releaseProximityLock() {
        if (proximityLock != null && proximityLock.isHeld()) {
            proximityLock.release(PowerManager.RELEASE_FLAG_WAIT_FOR_NO_PROXIMITY);
        }
        proximityLock = null;
    }
}
