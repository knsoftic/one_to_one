package com.hunario.chat;

import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.TextView;
import android.widget.Toast;
import androidx.core.content.ContextCompat;
import androidx.core.splashscreen.SplashScreen;
import com.getcapacitor.BridgeActivity;
import com.getcapacitor.Logger;
import com.getcapacitor.WebViewListener;
import java.lang.ref.WeakReference;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import org.json.JSONObject;

public class MainActivity extends BridgeActivity {

    static final String EXTRA_OPEN_URL = "com.hunario.chat.OPEN_URL";
    static final String EXTRA_CONVERSATION_ID = "com.hunario.chat.CONVERSATION_ID";
    static final String EXTRA_CALL_ID = "com.hunario.chat.CALL_ID";
    static final String EXTRA_CALL_ANSWER = "com.hunario.chat.CALL_ANSWER";

    private static WeakReference<MainActivity> current = new WeakReference<>(null);

    /** Never keep the splash screen longer than this, even on a very slow network. */
    private static final long SPLASH_TIMEOUT_MS = 8000;

    /** A page still loading after this long gets the "slow internet" screen. */
    private static final long SLOW_LOAD_MS = 8000;

    private static volatile boolean inForeground = false;
    private static volatile long activeConversationId = 0;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable slowLoadCheck = this::showConnectionScreen;

    private volatile boolean contentReady = false;
    private View connectionScreen;
    private ConnectivityManager.NetworkCallback networkCallback;

    /** The chat open on screen (set by the web app); 0 when none. */
    static void setActiveConversation(long conversationId) {
        activeConversationId = Math.max(0, conversationId);
    }

    /** True when that chat is visible right now, so a phone notification would be redundant. */
    static boolean isViewingConversation(long conversationId) {
        return inForeground && conversationId > 0 && activeConversationId == conversationId;
    }

    static boolean isInForeground() {
        return inForeground;
    }

    /** During a call the app stays visible over the lock screen, like the phone app. */
    static void showOverLockScreen(boolean show) {
        MainActivity activity = current.get();
        if (activity == null) {
            return;
        }
        activity.runOnUiThread(() -> {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
                activity.setShowWhenLocked(show);
                activity.setTurnScreenOn(show);
            } else if (show) {
                activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
            } else {
                activity.getWindow().clearFlags(WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED | WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON);
            }
            if (show) {
                activity.getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            } else {
                activity.getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
            }
        });
    }

    /**
     * Run a script in the open page and wait briefly for its result (called from background threads).
     *
     * @return the script's JSON result, or null when no page answered in time
     */
    static String evaluateInPage(String script, long timeoutMs) {
        MainActivity activity = current.get();
        if (activity == null || activity.bridge == null || activity.bridge.getWebView() == null || !inForeground) {
            return null;
        }

        final CountDownLatch latch = new CountDownLatch(1);
        final String[] result = { null };
        activity.runOnUiThread(() -> {
            try {
                activity.bridge.getWebView().evaluateJavascript(script, (value) -> {
                    result[0] = value;
                    latch.countDown();
                });
            } catch (RuntimeException exception) {
                latch.countDown();
            }
        });

        try {
            latch.await(timeoutMs, TimeUnit.MILLISECONDS);
        } catch (InterruptedException exception) {
            Thread.currentThread().interrupt();
        }
        return result[0];
    }

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        SplashScreen splashScreen = SplashScreen.installSplashScreen(this);
        splashScreen.setKeepOnScreenCondition(() -> !contentReady);

        registerPlugin(NativeAppPlugin.class);
        super.onCreate(savedInstanceState);
        current = new WeakReference<>(this);
        handleCallIntent(getIntent());

        handler.postDelayed(() -> contentReady = true, SPLASH_TIMEOUT_MS);

        if (bridge == null || bridge.getWebView() == null) {
            // No usable WebView on this device; Capacitor shows its own error layout.
            contentReady = true;
            return;
        }

        bridge.addWebViewListener(
            new WebViewListener() {
                @Override
                public void onPageStarted(WebView webView) {
                    handler.removeCallbacks(slowLoadCheck);
                    handler.postDelayed(slowLoadCheck, SLOW_LOAD_MS);
                }

                @Override
                public void onPageCommitVisible(WebView view, String url) {
                    contentReady = true;
                    handler.removeCallbacks(slowLoadCheck);
                    hideConnectionScreen();
                }

                @Override
                public void onReceivedError(WebView webView) {
                    // The offline page (server.errorPath) is shown next.
                    contentReady = true;
                }
            }
        );

        // The first page started loading before the listener was added.
        handler.postDelayed(slowLoadCheck, SLOW_LOAD_MS);

        bridge.getWebView().setDownloadListener(this::startDownload);
        registerNetworkCallback();

        // Opened from a message notification: go straight to that chat.
        String url = openUrlFrom(getIntent());
        if (url != null) {
            bridge.getWebView().loadUrl(url);
        }

        // Notifications: refresh the Firebase push token, or keep the fallback connection running
        // (a foreground service may only be started while the app is visible).
        ChatNotificationService.start(this);
        KeepAliveReceiver.schedule(this);
        PushRegistrar.register(this);
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        unregisterNetworkCallback();
        super.onDestroy();
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);

        long callId = handleCallIntent(intent);
        boolean answer = intent.getBooleanExtra(EXTRA_CALL_ANSWER, false);

        String url = openUrlFrom(intent);
        if (url == null || bridge == null || bridge.getWebView() == null) {
            return;
        }

        if (callId > 0) {
            // The chat page is open: answer in place; otherwise load the call link.
            String callScript =
                "(function(){if(window.Chat&&window.Chat.calls&&window.Chat.calls.enabled){window.Chat.calls.resumeFromLink(" +
                callId +
                "," +
                answer +
                ");}else{window.location.assign(" +
                JSONObject.quote(url) +
                ");}})();";
            bridge.getWebView().evaluateJavascript(callScript, null);
            return;
        }

        long conversationId = intent.getLongExtra(EXTRA_CONVERSATION_ID, 0);
        String script =
            "(function(){var id=" +
            conversationId +
            ";if(id&&window.Chat&&typeof window.Chat.openConversation==='function'){window.Chat.openConversation(id);}" +
            "else{window.location.assign(" +
            JSONObject.quote(url) +
            ");}})();";
        bridge.getWebView().evaluateJavascript(script, null);
    }

    /**
     * Opened by answering a call: stop ringing and show the app over the lock screen.
     *
     * @return the call id, or 0 when the intent is not about a call
     */
    private long handleCallIntent(Intent intent) {
        long callId = intent == null ? 0 : intent.getLongExtra(EXTRA_CALL_ID, 0);
        if (callId > 0) {
            CallNotifier.cancelIncoming(this, callId);
            CallRinger.stop();
            showOverLockScreen(true);
        }
        return callId;
    }

    @Override
    public void onResume() {
        super.onResume();
        current = new WeakReference<>(this);
        inForeground = true;
    }

    @Override
    public void onPause() {
        inForeground = false;
        super.onPause();
    }

    /* ------------------------------------------------------------------ */
    /* Slow / no internet screen                                           */
    /* ------------------------------------------------------------------ */

    private void showConnectionScreen() {
        if (isFinishing() || bridge == null) {
            return;
        }
        contentReady = true;

        if (connectionScreen == null) {
            ViewGroup root = findViewById(android.R.id.content);
            connectionScreen = getLayoutInflater().inflate(R.layout.connection_screen, root, false);
            connectionScreen.findViewById(R.id.connection_retry).setOnClickListener((view) -> retryLoading());
            root.addView(connectionScreen);
        }

        boolean online = hasInternet();
        ((TextView) connectionScreen.findViewById(R.id.connection_title)).setText(online ? R.string.slow_title : R.string.offline_title);
        ((TextView) connectionScreen.findViewById(R.id.connection_message)).setText(online ? R.string.slow_message : R.string.offline_message);
        connectionScreen.findViewById(R.id.connection_progress).setVisibility(View.VISIBLE);
        connectionScreen.setVisibility(View.VISIBLE);
    }

    private void hideConnectionScreen() {
        if (connectionScreen != null) {
            connectionScreen.setVisibility(View.GONE);
        }
    }

    private void retryLoading() {
        if (bridge == null || bridge.getWebView() == null) {
            return;
        }

        handler.removeCallbacks(slowLoadCheck);
        handler.postDelayed(slowLoadCheck, SLOW_LOAD_MS);

        String current = bridge.getWebView().getUrl();
        if (current != null && isAppHost(Uri.parse(current))) {
            bridge.getWebView().reload();
        } else {
            bridge.getWebView().loadUrl(bridge.getServerUrl());
        }
    }

    private boolean hasInternet() {
        ConnectivityManager connectivity = ContextCompat.getSystemService(this, ConnectivityManager.class);
        if (connectivity == null) {
            return true;
        }
        Network network = connectivity.getActiveNetwork();
        NetworkCapabilities capabilities = network == null ? null : connectivity.getNetworkCapabilities(network);
        return capabilities != null && capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
    }

    /** Reload automatically when the internet comes back while the connection screen is up. */
    private void registerNetworkCallback() {
        ConnectivityManager connectivity = ContextCompat.getSystemService(this, ConnectivityManager.class);
        if (connectivity == null) {
            return;
        }

        networkCallback = new ConnectivityManager.NetworkCallback() {
            @Override
            public void onAvailable(Network network) {
                handler.post(() -> {
                    if (connectionScreen != null && connectionScreen.getVisibility() == View.VISIBLE) {
                        retryLoading();
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

    private String openUrlFrom(Intent intent) {
        if (intent == null || bridge == null) {
            return null;
        }

        String url = intent.getStringExtra(EXTRA_OPEN_URL);
        intent.removeExtra(EXTRA_OPEN_URL);

        return url != null && isAppHost(Uri.parse(url)) ? url : null;
    }

    /**
     * Documents and "Download" actions are served with Content-Disposition: attachment, which a
     * WebView cannot handle on its own. Hand them to the system download manager, forwarding the
     * session cookie only to our own server so private attachments stay authorized.
     */
    private void startDownload(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
        Uri uri = Uri.parse(url);
        String scheme = uri.getScheme();

        if (!"https".equalsIgnoreCase(scheme) && !"http".equalsIgnoreCase(scheme)) {
            Toast.makeText(this, R.string.download_unsupported, Toast.LENGTH_SHORT).show();
            return;
        }

        String fileName = URLUtil.guessFileName(url, contentDisposition, mimeType);

        DownloadManager.Request request = new DownloadManager.Request(uri)
            .setTitle(fileName)
            .setMimeType(mimeType)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .addRequestHeader("User-Agent", userAgent);

        if (isAppHost(uri)) {
            String cookies = CookieManager.getInstance().getCookie(url);
            if (cookies != null) {
                request.addRequestHeader("Cookie", cookies);
            }
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, getString(R.string.app_name) + "/" + fileName);
        } else {
            request.setDestinationInExternalFilesDir(this, Environment.DIRECTORY_DOWNLOADS, fileName);
        }

        try {
            DownloadManager manager = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            manager.enqueue(request);
            Toast.makeText(this, getString(R.string.download_started, fileName), Toast.LENGTH_SHORT).show();
        } catch (Exception exception) {
            Logger.error("Download failed", exception);
            Toast.makeText(this, R.string.download_failed, Toast.LENGTH_SHORT).show();
        }
    }

    private boolean isAppHost(Uri uri) {
        String serverUrl = bridge.getServerUrl();
        if (serverUrl == null || uri.getHost() == null) {
            return false;
        }

        return uri.getHost().equalsIgnoreCase(Uri.parse(serverUrl).getHost());
    }
}
