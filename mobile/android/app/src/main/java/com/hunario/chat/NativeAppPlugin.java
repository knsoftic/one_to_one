package com.hunario.chat;

import android.Manifest;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageInfo;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.PowerManager;
import android.provider.ContactsContract.CommonDataKinds.Phone;
import android.provider.Settings;
import androidx.core.app.NotificationManagerCompat;
import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;
import java.lang.ref.WeakReference;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONObject;

/**
 * App-specific native features used by the web app:
 *  - getInfo(): app version
 *  - getContacts(): read-only phone book (names + numbers) for matching registered users
 *  - message notifications (Firebase push or the fallback connection)
 *  - calls: ringtone, ongoing-call service, speaker, Hang up from the notification
 */
@CapacitorPlugin(
    name = "One2OneNative",
    permissions = {
        @Permission(strings = { Manifest.permission.READ_CONTACTS }, alias = NativeAppPlugin.CONTACTS),
        @Permission(strings = { Manifest.permission.POST_NOTIFICATIONS }, alias = NativeAppPlugin.NOTIFICATIONS),
        @Permission(strings = { Manifest.permission.RECORD_AUDIO }, alias = NativeAppPlugin.MICROPHONE),
        @Permission(strings = { Manifest.permission.CAMERA }, alias = NativeAppPlugin.CAMERA),
    }
)
public class NativeAppPlugin extends Plugin {

    static final String CONTACTS = "contacts";
    static final String NOTIFICATIONS = "notifications";
    static final String MICROPHONE = "microphone";
    static final String CAMERA = "camera";

    private static WeakReference<NativeAppPlugin> instance = new WeakReference<>(null);

    private static final int MAX_CONTACTS = 20000;
    private static final int MAX_PHONES_PER_CONTACT = 10;

    private final ExecutorService executor = Executors.newSingleThreadExecutor();

    @Override
    public void load() {
        super.load();
        instance = new WeakReference<>(this);
    }

    /**
     * Forward a button pressed outside the web view (Hang up on the ongoing-call
     * notification) to the web app.
     *
     * @return false when no page is listening (the app should end the call itself)
     */
    static boolean emitCallAction(String action, long callId) {
        NativeAppPlugin plugin = instance.get();
        if (plugin == null || plugin.bridge == null || !plugin.hasListeners("callAction")) {
            return false;
        }
        JSObject data = new JSObject();
        data.put("action", action);
        data.put("callId", callId);
        plugin.notifyListeners("callAction", data, true);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Calls                                                               */
    /* ------------------------------------------------------------------ */

    /** The phone's ringtone + vibration for a call ringing while the app is open. */
    @PluginMethod
    public void startRingtone(PluginCall call) {
        CallRinger.start(getContext());
        call.resolve();
    }

    @PluginMethod
    public void stopRingtone(PluginCall call) {
        CallRinger.stop();
        call.resolve();
    }

    /**
     * A call started on this phone ({ callId, conversationId, type, name, speaker }): keep it
     * running in the background and show the ongoing-call notification.
     */
    @PluginMethod
    public void startCall(PluginCall call) {
        JSObject data = call.getData();
        long callId = data.optLong("callId", 0);

        CallRinger.stop();
        if (callId > 0) {
            CallNotifier.cancelIncoming(getContext(), callId);
        }

        OngoingCallService.start(
            getContext(),
            callId,
            data.optLong("conversationId", 0),
            "video".equals(data.optString("type")),
            data.optString("name", ""),
            data.optBoolean("speaker", "video".equals(data.optString("type")))
        );
        MainActivity.showOverLockScreen(true);
        call.resolve();
    }

    /** The call connected ({ connectedAt } in milliseconds): the notification shows a timer. */
    @PluginMethod
    public void updateCall(PluginCall call) {
        OngoingCallService.markConnected(getContext(), call.getData().optLong("connectedAt", System.currentTimeMillis()));
        call.resolve();
    }

    @PluginMethod
    public void endCall(PluginCall call) {
        CallRinger.stop();
        OngoingCallService.stop(getContext());
        MainActivity.showOverLockScreen(false);
        call.resolve();
    }

    /** { on: true } = loudspeaker, false = earpiece or headset. */
    @PluginMethod
    public void setSpeakerphone(PluginCall call) {
        OngoingCallService.setSpeaker(getContext(), call.getData().optBoolean("on", false));
        call.resolve();
    }

    @PluginMethod
    public void getInfo(PluginCall call) {
        Context context = getContext();
        JSObject result = new JSObject();
        result.put("platform", "android");

        try {
            PackageInfo info = context.getPackageManager().getPackageInfo(context.getPackageName(), 0);
            result.put("version", info.versionName);
            result.put("build", Build.VERSION.SDK_INT >= Build.VERSION_CODES.P ? info.getLongVersionCode() : info.versionCode);
        } catch (PackageManager.NameNotFoundException ignored) {
            // Version info is optional.
        }

        call.resolve(result);
    }

    /* ------------------------------------------------------------------ */
    /* Permissions                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * State of every permission the app uses: "granted", "prompt", "prompt-with-rationale" or "denied".
     */
    @PluginMethod
    public void getPermissions(PluginCall call) {
        call.resolve(permissionStates());
    }

    /** Show the system dialog for one permission ({ name: "notifications" | "contacts" | "microphone" | "camera" }). */
    @PluginMethod
    public void requestPermission(PluginCall call) {
        String name = call.getString("name", "");

        if (!CONTACTS.equals(name) && !NOTIFICATIONS.equals(name) && !MICROPHONE.equals(name) && !CAMERA.equals(name)) {
            call.reject("Unknown permission.", "UNKNOWN_PERMISSION");
            return;
        }

        if (stateOf(name).equals("granted") || (NOTIFICATIONS.equals(name) && Build.VERSION.SDK_INT < 33)) {
            resolveState(call, name);
            return;
        }

        requestPermissionForAlias(name, call, "singlePermissionCallback");
    }

    @PermissionCallback
    private void singlePermissionCallback(PluginCall call) {
        resolveState(call, call.getString("name", ""));
    }

    private void resolveState(PluginCall call, String name) {
        JSObject result = new JSObject();
        result.put("name", name);
        result.put("state", stateOf(name));
        call.resolve(result);
    }

    private JSObject permissionStates() {
        JSObject result = new JSObject();
        result.put(NOTIFICATIONS, stateOf(NOTIFICATIONS));
        result.put(CONTACTS, stateOf(CONTACTS));
        result.put(MICROPHONE, stateOf(MICROPHONE));
        result.put(CAMERA, stateOf(CAMERA));
        result.put("batteryUnrestricted", isIgnoringBatteryOptimizations());
        return result;
    }

    private String stateOf(String alias) {
        if (NOTIFICATIONS.equals(alias)) {
            return notificationPermission();
        }
        return getPermissionState(alias).toString();
    }

    /* ------------------------------------------------------------------ */
    /* Contacts                                                            */
    /* ------------------------------------------------------------------ */

    @PluginMethod
    public void getContacts(PluginCall call) {
        if (getPermissionState(CONTACTS) != PermissionState.GRANTED) {
            requestPermissionForAlias(CONTACTS, call, "contactsPermissionCallback");
            return;
        }

        readContacts(call);
    }

    @PermissionCallback
    private void contactsPermissionCallback(PluginCall call) {
        if (getPermissionState(CONTACTS) != PermissionState.GRANTED) {
            call.reject("Contacts permission was denied.", "PERMISSION_DENIED");
            return;
        }

        readContacts(call);
    }

    private void readContacts(PluginCall call) {
        executor.execute(() -> {
            Map<String, Entry> entries = new LinkedHashMap<>();
            String[] projection = { Phone.CONTACT_ID, Phone.DISPLAY_NAME_PRIMARY, Phone.NUMBER };

            try (
                Cursor cursor = getContext()
                    .getContentResolver()
                    .query(Phone.CONTENT_URI, projection, null, null, Phone.DISPLAY_NAME_PRIMARY + " COLLATE NOCASE ASC")
            ) {
                if (cursor != null) {
                    int idColumn = cursor.getColumnIndexOrThrow(Phone.CONTACT_ID);
                    int nameColumn = cursor.getColumnIndexOrThrow(Phone.DISPLAY_NAME_PRIMARY);
                    int numberColumn = cursor.getColumnIndexOrThrow(Phone.NUMBER);

                    while (cursor.moveToNext()) {
                        String number = cursor.getString(numberColumn);
                        if (number == null || number.trim().isEmpty()) {
                            continue;
                        }

                        String id = cursor.getString(idColumn);
                        Entry entry = entries.get(id);
                        if (entry == null) {
                            if (entries.size() >= MAX_CONTACTS) {
                                continue;
                            }
                            entry = new Entry(cursor.getString(nameColumn));
                            entries.put(id, entry);
                        }

                        String trimmed = number.trim();
                        if (entry.phones.size() < MAX_PHONES_PER_CONTACT && !entry.phones.contains(trimmed)) {
                            entry.phones.add(trimmed);
                        }
                    }
                }
            } catch (Exception exception) {
                call.reject("Could not read contacts.", "READ_FAILED", exception);
                return;
            }

            JSArray contacts = new JSArray();
            for (Entry entry : entries.values()) {
                JSObject contact = new JSObject();
                contact.put("name", entry.name == null ? "" : entry.name);
                contact.put("phones", new JSArray(entry.phones));
                contacts.put(contact);
            }

            JSObject result = new JSObject();
            result.put("contacts", contacts);
            call.resolve(result);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Background notifications                                            */
    /* ------------------------------------------------------------------ */

    @PluginMethod
    public void getNotificationStatus(PluginCall call) {
        NotificationSettings settings = NotificationSettings.load(getContext());

        JSObject result = new JSObject();
        result.put("enabled", settings.isUsable());
        result.put("userId", settings.userId);
        result.put("running", ChatNotificationService.isRunning());
        // "push" = Firebase, "socket" = the app's own background connection, "" = not decided yet.
        result.put("mode", settings.mode);
        // Saved before calls existed: register again to get the call endpoints.
        result.put("callsReady", !settings.callDeclineUrl.isEmpty());
        result.put("permission", notificationPermission());
        result.put("ignoringBatteryOptimizations", isIgnoringBatteryOptimizations());
        call.resolve(result);
    }

    @PluginMethod
    public void requestNotificationPermission(PluginCall call) {
        if (Build.VERSION.SDK_INT < 33 || getPermissionState(NOTIFICATIONS) == PermissionState.GRANTED) {
            resolvePermission(call);
            return;
        }

        requestPermissionForAlias(NOTIFICATIONS, call, "notificationPermissionCallback");
    }

    @PermissionCallback
    private void notificationPermissionCallback(PluginCall call) {
        resolvePermission(call);
    }

    /**
     * Save the connection details returned by POST /devices and start the background service.
     */
    @PluginMethod
    public void enableNotifications(PluginCall call) {
        JSObject details = call.getData();
        JSONObject endpoints = details.optJSONObject("endpoints");

        boolean valid =
            !details.optString("token", "").isEmpty() &&
            endpoints != null &&
            isAppUrl(details.optString("server_url", "")) &&
            isAppUrl(endpoints.optString("notifications", "")) &&
            isAppUrl(endpoints.optString("auth", ""));

        if (!valid) {
            // The token must only ever be sent to the app's own server.
            call.reject("Invalid notification settings.", "INVALID_SETTINGS");
            return;
        }

        NotificationSettings.save(getContext(), details);
        // Stop a connection that belonged to the previous account; the registrar picks
        // Firebase push or restarts the fallback connection with the new details.
        ChatNotificationService.stop(getContext());
        PushRegistrar.register(getContext());
        call.resolve();
    }

    @PluginMethod
    public void disableNotifications(PluginCall call) {
        NotificationSettings.clear(getContext());
        KeepAliveReceiver.cancel(getContext());
        ChatNotificationService.stop(getContext());
        MessageNotifier.clearAll(getContext());
        call.resolve();
    }

    /**
     * The chat currently open on screen ({ conversationId } or 0). Its messages don't raise a
     * phone notification while the app is in the foreground; every other chat still does.
     */
    @PluginMethod
    public void setActiveConversation(PluginCall call) {
        MainActivity.setActiveConversation(call.getData().optLong("conversationId", 0));
        call.resolve();
    }

    @PluginMethod
    public void clearConversationNotifications(PluginCall call) {
        long conversationId = call.getData().optLong("conversationId", 0);
        if (conversationId > 0) {
            MessageNotifier.clearConversation(getContext(), conversationId);
        }
        call.resolve();
    }

    /**
     * Ask Android not to put the app to sleep, so messages keep arriving instantly
     * and the connection restarts on its own.
     */
    @PluginMethod
    public void requestBatteryOptimizationExemption(PluginCall call) {
        JSObject result = new JSObject();

        if (isIgnoringBatteryOptimizations()) {
            result.put("granted", true);
            call.resolve(result);
            return;
        }

        Context context = getContext();
        try {
            Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + context.getPackageName()));
            getActivity().startActivity(intent);
        } catch (ActivityNotFoundException exception) {
            try {
                getActivity().startActivity(new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS));
            } catch (ActivityNotFoundException ignored) {
                // No settings screen on this device.
            }
        }

        result.put("granted", false);
        result.put("requested", true);
        call.resolve(result);
    }

    private void resolvePermission(PluginCall call) {
        JSObject result = new JSObject();
        result.put("permission", notificationPermission());
        call.resolve(result);
    }

    private String notificationPermission() {
        if (NotificationManagerCompat.from(getContext()).areNotificationsEnabled()) {
            return "granted";
        }
        if (Build.VERSION.SDK_INT < 33) {
            return "denied"; // switched off in the system settings
        }
        return getPermissionState(NOTIFICATIONS).toString();
    }

    private boolean isIgnoringBatteryOptimizations() {
        PowerManager power = getContext().getSystemService(PowerManager.class);
        return power != null && power.isIgnoringBatteryOptimizations(getContext().getPackageName());
    }

    private boolean isAppUrl(String url) {
        String serverUrl = bridge.getServerUrl();
        if (url == null || url.isEmpty() || serverUrl == null) {
            return false;
        }

        Uri uri = Uri.parse(url);
        Uri server = Uri.parse(serverUrl);

        return "https".equalsIgnoreCase(uri.getScheme()) && uri.getHost() != null && uri.getHost().equalsIgnoreCase(server.getHost());
    }

    @Override
    protected void handleOnDestroy() {
        executor.shutdown();
        super.handleOnDestroy();
    }

    private static final class Entry {

        final String name;
        final List<String> phones = new ArrayList<>();

        Entry(String name) {
            this.name = name;
        }
    }
}
