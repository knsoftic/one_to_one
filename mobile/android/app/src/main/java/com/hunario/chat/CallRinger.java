package com.hunario.chat;

import android.content.Context;
import android.media.AudioAttributes;
import android.media.AudioManager;
import android.media.Ringtone;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.os.VibrationEffect;
import android.os.Vibrator;
import android.os.VibratorManager;

/**
 * The phone's own ringtone and vibration for a call ringing while the app is open
 * (the incoming-call notification rings by itself when the app is closed).
 * Follows the ringer mode: silent, vibrate only, or ring.
 */
final class CallRinger {

    private static final long MAX_RING_MS = 70_000;
    private static final long[] VIBRATION = { 0, 900, 900 };

    private static final Handler handler = new Handler(Looper.getMainLooper());
    private static Ringtone ringtone;
    private static Vibrator vibrator;

    private CallRinger() {}

    static synchronized void start(Context context) {
        stop();
        Context app = context.getApplicationContext();
        AudioManager audio = app.getSystemService(AudioManager.class);
        int mode = audio == null ? AudioManager.RINGER_MODE_NORMAL : audio.getRingerMode();

        if (mode == AudioManager.RINGER_MODE_NORMAL) {
            try {
                Uri uri = RingtoneManager.getActualDefaultRingtoneUri(app, RingtoneManager.TYPE_RINGTONE);
                if (uri == null) {
                    uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE);
                }
                ringtone = RingtoneManager.getRingtone(app, uri);
                if (ringtone != null) {
                    ringtone.setAudioAttributes(
                        new AudioAttributes.Builder()
                            .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                            .build()
                    );
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                        ringtone.setLooping(true);
                    }
                    ringtone.play();
                }
            } catch (RuntimeException ignored) {
                ringtone = null;
            }
        }

        if (mode != AudioManager.RINGER_MODE_SILENT) {
            vibrator = vibrator(app);
            if (vibrator != null && vibrator.hasVibrator()) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    vibrator.vibrate(VibrationEffect.createWaveform(VIBRATION, 0));
                } else {
                    vibrator.vibrate(VIBRATION, 0);
                }
            }
        }

        handler.postDelayed(CallRinger::stop, MAX_RING_MS);
    }

    static synchronized void stop() {
        handler.removeCallbacksAndMessages(null);
        if (ringtone != null) {
            try {
                ringtone.stop();
            } catch (RuntimeException ignored) {
                // Already stopped.
            }
            ringtone = null;
        }
        if (vibrator != null) {
            vibrator.cancel();
            vibrator = null;
        }
    }

    private static Vibrator vibrator(Context context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            VibratorManager manager = context.getSystemService(VibratorManager.class);
            return manager == null ? null : manager.getDefaultVibrator();
        }
        return context.getSystemService(Vibrator.class);
    }
}
