package com.hunario.chat;

import android.content.Intent;
import android.graphics.Bitmap;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.view.WindowManager;
import android.widget.ImageButton;
import android.widget.ImageView;
import android.widget.TextView;
import androidx.appcompat.app.AppCompatActivity;
import java.lang.ref.WeakReference;

/**
 * Full-screen incoming call (also over the lock screen), like WhatsApp:
 * caller photo and name, Decline and Answer. Answering opens the chat and
 * connects the call in the app.
 */
public class IncomingCallActivity extends AppCompatActivity {

    private static WeakReference<IncomingCallActivity> current = new WeakReference<>(null);

    private final Handler handler = new Handler(Looper.getMainLooper());
    private IncomingCall call;

    /** Close the screen when the call stops ringing (answered elsewhere, cancelled…). */
    static void dismiss(long callId) {
        IncomingCallActivity activity = current.get();
        if (activity != null && activity.call != null && activity.call.callId == callId) {
            activity.runOnUiThread(activity::finish);
        }
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        showOverLockScreen();
        setContentView(R.layout.activity_incoming_call);

        if (!bind(getIntent())) {
            finish();
        }
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (!bind(intent)) {
            finish();
        }
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        if (current.get() == this) {
            current = new WeakReference<>(null);
        }
        super.onDestroy();
    }

    private boolean bind(Intent intent) {
        IncomingCall incoming = IncomingCall.from(intent);
        NotificationSettings settings = NotificationSettings.load(this);
        if (incoming == null || incoming.isExpired() || !settings.isUsable()) {
            return false;
        }

        call = incoming;
        current = new WeakReference<>(this);

        TextView name = findViewById(R.id.call_name);
        TextView status = findViewById(R.id.call_status);
        ImageView avatar = findViewById(R.id.call_avatar);
        ImageButton accept = findViewById(R.id.call_accept);
        ImageButton decline = findViewById(R.id.call_decline);

        name.setText(incoming.callerName.isEmpty() ? getString(R.string.app_name) : incoming.callerName);
        status.setText(incoming.isVideo() ? R.string.incoming_video_call : R.string.incoming_voice_call);
        accept.setImageResource(incoming.isVideo() ? R.drawable.ic_videocam : R.drawable.ic_call);
        accept.setContentDescription(getString(R.string.call_answer));

        accept.setOnClickListener((view) -> {
            CallNotifier.cancelIncoming(this, incoming.callId);
            startActivity(CallNotifier.answerActivityIntent(this, settings, incoming));
            finish();
        });

        decline.setOnClickListener((view) -> {
            CallActionReceiver.decline(this, incoming.callId);
            finish();
        });

        handler.removeCallbacksAndMessages(null);
        final WeakReference<ImageView> avatarRef = new WeakReference<>(avatar);
        new Thread(() -> {
            Bitmap bitmap = AvatarLoader.load(getApplicationContext(), settings, incoming.avatarUrl, incoming.initials, incoming.avatarHue);
            handler.post(() -> {
                ImageView view = avatarRef.get();
                if (view != null && !isFinishing()) {
                    view.setImageBitmap(bitmap);
                }
            });
        }, "one2one-call-avatar").start();

        handler.postDelayed(this::finish, Math.max(1000, incoming.expiresAt() - System.currentTimeMillis()));
        return true;
    }

    private void showOverLockScreen() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true);
            setTurnScreenOn(true);
        } else {
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
        }
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
    }
}
