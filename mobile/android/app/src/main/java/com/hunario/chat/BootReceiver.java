package com.hunario.chat;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * Reconnects background notifications after the phone restarts or the app is updated.
 */
public class BootReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent == null ? null : intent.getAction();

        if (
            Intent.ACTION_BOOT_COMPLETED.equals(action) ||
            Intent.ACTION_MY_PACKAGE_REPLACED.equals(action) ||
            "android.intent.action.QUICKBOOT_POWERON".equals(action)
        ) {
            // Firebase push needs nothing; the fallback connection is restarted.
            ChatNotificationService.start(context);
            KeepAliveReceiver.schedule(context);
        }
    }
}
