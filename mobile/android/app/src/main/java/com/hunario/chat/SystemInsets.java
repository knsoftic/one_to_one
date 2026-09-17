package com.hunario.chat;

import android.content.res.Configuration;
import android.graphics.Color;
import android.os.Build;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.webkit.WebView;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import androidx.webkit.ScriptHandler;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;
import java.util.Collections;
import java.util.Locale;

/**
 * Status and navigation bars (app 1.2+).
 *
 * Android 8 and newer: the page is drawn behind the status and navigation bars, so the indigo
 * header continues behind the status bar. Their heights reach the page as CSS variables
 * (--safe-area-inset-top / -bottom, in dp) before its first paint; env(safe-area-inset-*) is not
 * used because older WebViews report it wrongly. Side bars and camera cutouts (landscape) and the
 * keyboard shrink the page instead.
 *
 * Android 7 can't show dark navigation buttons, so there the page stays between the bars
 * (indigo status bar, black navigation bar) and the variables are 0.
 *
 * html[data-native-insets] is "edge" or "fitted"; resources/js/native/system-bars.js reads it.
 */
final class SystemInsets {

    /** Status bar colour where the page can't be drawn behind it (the header's indigo). */
    private static final int FITTED_STATUS_BAR = 0xFF4338CA;

    private final Window window;
    private final WebView webView;
    private final boolean edgeToEdge;

    private int top = -1;
    private int bottom;
    private ScriptHandler documentStartScript;

    private SystemInsets(Window window, WebView webView) {
        this.window = window;
        this.webView = webView;
        this.edgeToEdge = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O;
    }

    static SystemInsets install(Window window, WebView webView) {
        SystemInsets insets = new SystemInsets(window, webView);
        insets.apply();
        return insets;
    }

    private void apply() {
        if (!edgeToEdge) {
            // The framework keeps the page between the bars (and above the keyboard).
            window.setStatusBarColor(FITTED_STATUS_BAR);
            window.setNavigationBarColor(Color.BLACK);
            update(0, 0);
            return;
        }

        WindowCompat.setDecorFitsSystemWindows(window, false);
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.VANILLA_ICE_CREAM) {
            window.setStatusBarColor(Color.TRANSPARENT);
            window.setNavigationBarColor(Color.TRANSPARENT);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            window.setStatusBarContrastEnforced(false);
            window.setNavigationBarContrastEnforced(false);
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            WindowManager.LayoutParams attributes = window.getAttributes();
            attributes.layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES;
            window.setAttributes(attributes);
        }

        View decor = window.getDecorView();
        // Replaces Capacitor's SystemBars listener, which on many phones left the page below a white status bar.
        ViewCompat.setOnApplyWindowInsetsListener(decor, (view, windowInsets) -> {
            Insets bars = windowInsets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            Insets ime = windowInsets.getInsets(WindowInsetsCompat.Type.ime());
            boolean keyboardVisible = windowInsets.isVisible(WindowInsetsCompat.Type.ime());

            // Top and bottom bars are drawn over the page (it leaves room with the CSS variables);
            // side bars, cutouts and the keyboard make the page smaller (interactive-widget=resizes-content).
            view.setPadding(bars.left, 0, bars.right, keyboardVisible ? Math.max(ime.bottom, bars.bottom) : 0);
            update(bars.top, keyboardVisible ? 0 : bars.bottom);

            // Nothing below the decor view may add its own padding for the bars.
            return WindowInsetsCompat.CONSUMED;
        });
        ViewCompat.requestApplyInsets(decor);
    }

    /** Call when a page became visible (a new document has no variables yet). */
    void onPageVisible() {
        inject();
        if (edgeToEdge) {
            ViewCompat.requestApplyInsets(window.getDecorView());
        }
    }

    /** The native connection screen covers the page: bar icons that show on its plain background. */
    void useAppBackgroundIcons() {
        boolean night = (webView.getResources().getConfiguration().uiMode & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        WindowInsetsControllerCompat controller = WindowCompat.getInsetsController(window, window.getDecorView());
        controller.setAppearanceLightStatusBars(edgeToEdge && !night);
        controller.setAppearanceLightNavigationBars(edgeToEdge && !night);
    }

    private void update(int topPx, int bottomPx) {
        // Read every time: the display size setting or a foldable's other screen can change it.
        float density = webView.getResources().getDisplayMetrics().density;
        int newTop = Math.round(topPx / density);
        int newBottom = Math.round(bottomPx / density);
        if (newTop == top && newBottom == bottom) {
            return;
        }
        top = newTop;
        bottom = newBottom;

        registerDocumentStartScript();
        inject();
    }

    private String script() {
        return String.format(
            Locale.US,
            "(function(){try{var r=document.documentElement,s=r.style;" +
            "s.setProperty('--safe-area-inset-top','%dpx');s.setProperty('--safe-area-inset-right','0px');" +
            "s.setProperty('--safe-area-inset-bottom','%dpx');s.setProperty('--safe-area-inset-left','0px');" +
            "r.setAttribute('data-native-insets','%s');}catch(e){}})();",
            Math.max(top, 0),
            bottom,
            edgeToEdge ? "edge" : "fitted"
        );
    }

    /** Every new page starts with the right sizes, so the header never jumps. */
    private void registerDocumentStartScript() {
        if (!WebViewFeature.isFeatureSupported(WebViewFeature.DOCUMENT_START_SCRIPT)) {
            return;
        }
        try {
            if (documentStartScript != null) {
                documentStartScript.remove();
            }
            documentStartScript = WebViewCompat.addDocumentStartJavaScript(webView, script(), Collections.singleton("*"));
        } catch (RuntimeException ignored) {
            // Falls back to setting the variables when the page is visible.
        }
    }

    private void inject() {
        if (top < 0) {
            return;
        }
        webView.post(() -> webView.evaluateJavascript(script(), null));
    }
}
