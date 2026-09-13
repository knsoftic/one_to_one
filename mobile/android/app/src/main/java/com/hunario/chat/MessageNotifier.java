package com.hunario.chat;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.graphics.Bitmap;
import android.os.Build;
import android.service.notification.StatusBarNotification;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.core.app.Person;
import androidx.core.app.RemoteInput;
import androidx.core.content.ContextCompat;
import androidx.core.content.LocusIdCompat;
import androidx.core.content.pm.ShortcutInfoCompat;
import androidx.core.content.pm.ShortcutManagerCompat;
import androidx.core.graphics.drawable.IconCompat;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;

/**
 * WhatsApp-style message notifications:
 *  - one notification per chat, in conversation style, with the sender's photo
 *  - earlier messages of the chat stay listed (rebuilt from the notification shown)
 *  - Reply and Mark as read buttons
 *  - a conversation shortcut (Android 11+ "Conversations" section, launcher shortcut)
 *  - a group summary when several chats have new messages
 * plus the quiet notification required for the fallback background connection.
 */
final class MessageNotifier {

    static final String CHANNEL_MESSAGES = "messages";
    static final String CHANNEL_CONNECTION = "background_connection";
    static final int CONNECTION_NOTIFICATION_ID = 1001;
    static final String KEY_REPLY_TEXT = "reply_text";

    private static final int SUMMARY_NOTIFICATION_ID = 1002;
    private static final String TAG_CONVERSATION = "conversation";
    private static final String GROUP_MESSAGES = "com.hunario.chat.MESSAGES";
    private static final String ME_KEY = "me";

    private MessageNotifier() {}

    static void createChannels(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }

        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) {
            return;
        }

        NotificationChannel messages = new NotificationChannel(
            CHANNEL_MESSAGES,
            context.getString(R.string.channel_messages),
            NotificationManager.IMPORTANCE_HIGH
        );
        messages.setDescription(context.getString(R.string.channel_messages_description));
        messages.enableVibration(true);
        messages.enableLights(true);
        messages.setLightColor(0xFF6366F1);
        messages.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);

        NotificationChannel connection = new NotificationChannel(
            CHANNEL_CONNECTION,
            context.getString(R.string.channel_connection),
            NotificationManager.IMPORTANCE_MIN
        );
        connection.setDescription(context.getString(R.string.channel_connection_description));
        connection.setShowBadge(false);

        manager.createNotificationChannel(messages);
        manager.createNotificationChannel(connection);
    }

    static Notification connectionNotification(Context context, boolean connected) {
        return new NotificationCompat.Builder(context, CHANNEL_CONNECTION)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(context, R.color.brand))
            .setContentTitle(context.getString(R.string.app_name))
            .setContentText(context.getString(connected ? R.string.connection_connected : R.string.connection_connecting))
            .setPriority(NotificationCompat.PRIORITY_MIN)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setOngoing(true)
            .setShowWhen(false)
            .setContentIntent(openChatIntent(context, 0, null))
            .build();
    }

    /* ------------------------------------------------------------------ */
    /* Message notifications                                               */
    /* ------------------------------------------------------------------ */

    /** Show a new message. Call off the main thread (loads the sender's photo). */
    static void showMessage(Context context, NotificationSettings settings, ChatNotification message) {
        createChannels(context);

        String name = message.senderName.isEmpty() ? context.getString(R.string.app_name) : message.senderName;
        String body = settings.showPreview && !message.body.isEmpty() ? message.body : context.getString(R.string.notification_new_message);

        Bitmap avatar = AvatarLoader.load(context, settings, message);
        IconCompat icon = IconCompat.createWithBitmap(avatar);
        Person sender = new Person.Builder().setKey("user-" + message.senderId).setName(name).setIcon(icon).build();

        synchronized (MessageNotifier.class) {
            NotificationCompat.MessagingStyle style = existingStyle(context, message.conversationId);
            if (style == null) {
                style = new NotificationCompat.MessagingStyle(me(context));
            }
            style.addMessage(new NotificationCompat.MessagingStyle.Message(body, message.timestamp, sender));

            String shortcutId = publishShortcut(context, settings, message.conversationId, sender, icon);
            post(context, settings, message.conversationId, name, style, avatar, shortcutId, false);
            updateSummary(context);
        }
    }

    /** A reply typed in the notification could not be sent: show it as not sent. */
    static void showReplyFailed(Context context, NotificationSettings settings, long conversationId, CharSequence text) {
        synchronized (MessageNotifier.class) {
            NotificationCompat.MessagingStyle style = existingStyle(context, conversationId);
            if (style == null) {
                style = new NotificationCompat.MessagingStyle(me(context));
            }
            style.addMessage(
                new NotificationCompat.MessagingStyle.Message(
                    context.getString(R.string.reply_not_sent, text),
                    System.currentTimeMillis(),
                    (Person) null
                )
            );

            String title = context.getString(R.string.app_name);
            for (NotificationCompat.MessagingStyle.Message item : style.getMessages()) {
                Person person = item.getPerson();
                if (person != null && person.getName() != null && !ME_KEY.equals(person.getKey())) {
                    title = person.getName().toString();
                }
            }

            post(context, settings, conversationId, title, style, null, "conversation-" + conversationId, true);
        }
    }

    static synchronized void clearConversation(Context context, long conversationId) {
        NotificationManagerCompat.from(context).cancel(TAG_CONVERSATION, (int) conversationId);
        updateSummary(context);
    }

    /** Remove notifications of chats that are no longer unread (read on another device). */
    static synchronized void retainOnly(Context context, Set<Long> unreadConversationIds) {
        for (StatusBarNotification notification : conversationNotifications(context)) {
            if (!unreadConversationIds.contains((long) notification.getId())) {
                NotificationManagerCompat.from(context).cancel(TAG_CONVERSATION, notification.getId());
            }
        }
        updateSummary(context);
    }

    static synchronized void clearAll(Context context) {
        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        for (StatusBarNotification notification : conversationNotifications(context)) {
            manager.cancel(TAG_CONVERSATION, notification.getId());
        }
        manager.cancel(SUMMARY_NOTIFICATION_ID);
        try {
            ShortcutManagerCompat.removeAllDynamicShortcuts(context);
        } catch (RuntimeException ignored) {
            // Shortcuts are optional.
        }
    }

    private static void post(
        Context context,
        NotificationSettings settings,
        long conversationId,
        String title,
        NotificationCompat.MessagingStyle style,
        Bitmap largeIcon,
        String shortcutId,
        boolean silent
    ) {
        int unread = 0;
        String lastText = "";
        for (NotificationCompat.MessagingStyle.Message item : style.getMessages()) {
            Person person = item.getPerson();
            if (person != null && !ME_KEY.equals(person.getKey())) {
                unread++;
            }
            lastText = item.getText() == null ? "" : item.getText().toString();
        }

        String url = settings.serverUrl + "/chat/" + conversationId;

        Notification publicVersion = new NotificationCompat.Builder(context, CHANNEL_MESSAGES)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(context, R.color.brand))
            .setContentTitle(context.getString(R.string.app_name))
            .setContentText(context.getResources().getQuantityString(R.plurals.new_messages, Math.max(1, unread), Math.max(1, unread)))
            .build();

        NotificationCompat.Builder builder = new NotificationCompat.Builder(context, CHANNEL_MESSAGES)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(context, R.color.brand))
            .setContentTitle(title)
            .setContentText(lastText)
            .setStyle(style)
            .setNumber(unread)
            .setShowWhen(true)
            .setAutoCancel(true)
            .setOnlyAlertOnce(silent)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_MESSAGE)
            .setDefaults(silent ? 0 : NotificationCompat.DEFAULT_ALL)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(publicVersion)
            .setGroup(GROUP_MESSAGES)
            .setShortcutId(shortcutId)
            .setLocusId(new LocusIdCompat(shortcutId))
            .setContentIntent(openChatIntent(context, conversationId, url));

        if (largeIcon != null) {
            builder.setLargeIcon(largeIcon);
        }

        if (!settings.replyUrl.isEmpty()) {
            builder.addAction(replyAction(context, conversationId));
        }
        if (!settings.readUrl.isEmpty()) {
            builder.addAction(markReadAction(context, conversationId));
        }

        notify(context, TAG_CONVERSATION, (int) conversationId, builder.build());
    }

    private static NotificationCompat.MessagingStyle existingStyle(Context context, long conversationId) {
        for (StatusBarNotification notification : conversationNotifications(context)) {
            if (notification.getId() == (int) conversationId) {
                return NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(notification.getNotification());
            }
        }
        return null;
    }

    private static List<StatusBarNotification> conversationNotifications(Context context) {
        List<StatusBarNotification> result = new ArrayList<>();
        try {
            for (StatusBarNotification notification : NotificationManagerCompat.from(context).getActiveNotifications()) {
                if (TAG_CONVERSATION.equals(notification.getTag())) {
                    result.add(notification);
                }
            }
        } catch (RuntimeException ignored) {
            // Not available: start a fresh conversation notification.
        }
        return result;
    }

    private static Person me(Context context) {
        return new Person.Builder().setKey(ME_KEY).setName(context.getString(R.string.notification_you)).build();
    }

    private static void updateSummary(Context context) {
        List<StatusBarNotification> conversations = conversationNotifications(context);
        if (conversations.size() < 2) {
            NotificationManagerCompat.from(context).cancel(SUMMARY_NOTIFICATION_ID);
            return;
        }

        int total = 0;
        NotificationCompat.InboxStyle style = new NotificationCompat.InboxStyle();
        for (StatusBarNotification notification : conversations) {
            NotificationCompat.MessagingStyle messages = NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(
                notification.getNotification()
            );
            if (messages == null || messages.getMessages().isEmpty()) {
                continue;
            }
            NotificationCompat.MessagingStyle.Message last = messages.getMessages().get(messages.getMessages().size() - 1);
            total += Math.max(1, notification.getNotification().number);
            CharSequence who = last.getPerson() != null ? last.getPerson().getName() : context.getString(R.string.notification_you);
            style.addLine(who + ": " + last.getText());
        }

        String messagesText = context.getResources().getQuantityString(R.plurals.new_messages, Math.max(1, total), Math.max(1, total));
        String text = context.getString(R.string.summary_messages_chats, messagesText, conversations.size());
        style.setSummaryText(text);

        Notification summary = new NotificationCompat.Builder(context, CHANNEL_MESSAGES)
            .setSmallIcon(R.drawable.ic_stat_chat)
            .setColor(ContextCompat.getColor(context, R.color.brand))
            .setContentTitle(context.getString(R.string.app_name))
            .setContentText(text)
            .setStyle(style)
            .setGroup(GROUP_MESSAGES)
            .setGroupSummary(true)
            .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
            .setAutoCancel(true)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setContentIntent(openChatIntent(context, 0, null))
            .build();

        notify(context, null, SUMMARY_NOTIFICATION_ID, summary);
    }

    /* ------------------------------------------------------------------ */
    /* Actions, shortcuts, intents                                         */
    /* ------------------------------------------------------------------ */

    private static NotificationCompat.Action replyAction(Context context, long conversationId) {
        Intent intent = new Intent(context, NotificationActionReceiver.class)
            .setAction(NotificationActionReceiver.ACTION_REPLY)
            .putExtra(NotificationActionReceiver.EXTRA_CONVERSATION_ID, conversationId);

        // RemoteInput needs a mutable PendingIntent (the explicit component keeps it safe).
        int flags = PendingIntent.FLAG_UPDATE_CURRENT | (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S ? PendingIntent.FLAG_MUTABLE : 0);
        PendingIntent pending = PendingIntent.getBroadcast(context, requestCode(conversationId, 1), intent, flags);

        RemoteInput input = new RemoteInput.Builder(KEY_REPLY_TEXT).setLabel(context.getString(R.string.action_reply_hint)).build();

        return new NotificationCompat.Action.Builder(
            IconCompat.createWithResource(context, R.drawable.ic_action_reply),
            context.getString(R.string.action_reply),
            pending
        )
            .addRemoteInput(input)
            .setAllowGeneratedReplies(true)
            .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_REPLY)
            .setShowsUserInterface(false)
            .build();
    }

    private static NotificationCompat.Action markReadAction(Context context, long conversationId) {
        Intent intent = new Intent(context, NotificationActionReceiver.class)
            .setAction(NotificationActionReceiver.ACTION_MARK_READ)
            .putExtra(NotificationActionReceiver.EXTRA_CONVERSATION_ID, conversationId);

        PendingIntent pending = PendingIntent.getBroadcast(
            context,
            requestCode(conversationId, 2),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );

        return new NotificationCompat.Action.Builder(
            IconCompat.createWithResource(context, R.drawable.ic_action_read),
            context.getString(R.string.action_mark_read),
            pending
        )
            .setSemanticAction(NotificationCompat.Action.SEMANTIC_ACTION_MARK_AS_READ)
            .setShowsUserInterface(false)
            .build();
    }

    private static String publishShortcut(Context context, NotificationSettings settings, long conversationId, Person person, IconCompat icon) {
        String id = "conversation-" + conversationId;
        try {
            Intent intent = new Intent(context, MainActivity.class)
                .setAction(Intent.ACTION_VIEW)
                .putExtra(MainActivity.EXTRA_OPEN_URL, settings.serverUrl + "/chat/" + conversationId)
                .putExtra(MainActivity.EXTRA_CONVERSATION_ID, conversationId);

            CharSequence label = person.getName() == null ? context.getString(R.string.app_name) : person.getName();
            ShortcutInfoCompat shortcut = new ShortcutInfoCompat.Builder(context, id)
                .setShortLabel(label)
                .setLongLabel(label)
                .setIcon(icon)
                .setPerson(person)
                .setLongLived(true)
                .setLocusId(new LocusIdCompat(id))
                .setIntent(intent)
                .build();

            ShortcutManagerCompat.pushDynamicShortcut(context, shortcut);
        } catch (RuntimeException ignored) {
            // Shortcuts are optional (e.g. rate limited by the launcher).
        }
        return id;
    }

    static PendingIntent openChatIntent(Context context, long conversationId, String url) {
        Intent intent = new Intent(context, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        if (url != null) {
            intent.putExtra(MainActivity.EXTRA_OPEN_URL, url);
            intent.putExtra(MainActivity.EXTRA_CONVERSATION_ID, conversationId);
        }

        return PendingIntent.getActivity(
            context,
            requestCode(conversationId, 0),
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
        );
    }

    private static int requestCode(long conversationId, int action) {
        return (int) (conversationId * 4 + action);
    }

    private static void notify(Context context, String tag, int id, Notification notification) {
        NotificationManagerCompat manager = NotificationManagerCompat.from(context);
        if (!manager.areNotificationsEnabled()) {
            return;
        }

        try {
            manager.notify(tag, id, notification);
        } catch (SecurityException ignored) {
            // Notification permission was revoked in the meantime.
        }
    }
}
