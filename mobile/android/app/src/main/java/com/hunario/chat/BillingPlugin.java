package com.hunario.chat;

import android.app.Activity;
import android.os.Handler;
import android.os.Looper;
import androidx.annotation.NonNull;
import androidx.annotation.Nullable;
import com.android.billingclient.api.BillingClient;
import com.android.billingclient.api.BillingClientStateListener;
import com.android.billingclient.api.BillingFlowParams;
import com.android.billingclient.api.BillingResult;
import com.android.billingclient.api.ConsumeParams;
import com.android.billingclient.api.PendingPurchasesParams;
import com.android.billingclient.api.ProductDetails;
import com.android.billingclient.api.Purchase;
import com.android.billingclient.api.PurchasesUpdatedListener;
import com.android.billingclient.api.QueryProductDetailsParams;
import com.android.billingclient.api.QueryPurchasesParams;
import com.getcapacitor.JSArray;
import com.getcapacitor.JSObject;
import com.getcapacitor.Logger;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import org.json.JSONException;

/**
 * Google Play Billing (Y2): coins and plans bought inside the Android app as consumable
 * in-app products.
 *
 * The plugin only talks to Google Play and hands the purchase token to the web app. It never
 * decides whether a purchase is valid or credits anything: the server verifies every token with
 * the Play Developer API, and the web app calls {@link #consume} only after the server answered
 * 200. An unconsumed purchase stays in Play's queue (see {@link #getPendingPurchases}) so it can
 * be delivered on the next app open when the network dropped or the app was killed in between.
 *
 * One BillingClient lives for the activity's lifetime and reconnects after a disconnect;
 * calls that arrive while it is not ready wait for the connection. Every PluginCall is
 * resolved on the main thread.
 */
@CapacitorPlugin(name = "One2OneBilling")
public class BillingPlugin extends Plugin implements PurchasesUpdatedListener {

    private static final String TAG = "One2OneBilling";

    /** JS-facing error codes (also the argument of {@code call.reject}). */
    static final String CODE_CANCELLED = "cancelled";
    static final String CODE_ALREADY_OWNED = "item_already_owned";
    static final String CODE_UNAVAILABLE = "unavailable";
    static final String CODE_ERROR = "error";

    private static final long RECONNECT_MIN_MS = 1000;
    private static final long RECONNECT_MAX_MS = 30_000;

    /** Serialises the plugin's own bookkeeping (the billing client itself is thread-safe). */
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());

    private BillingClient client;

    /** Work waiting for the connection; guarded by {@code this}. */
    private final List<ReadyListener> waiting = new ArrayList<>();
    private boolean connecting = false;
    private long reconnectDelayMs = RECONNECT_MIN_MS;
    private final Runnable reconnect = () -> connect();

    /** The purchase flow on screen, resolved from {@link #onPurchasesUpdated}; guarded by {@code this}. */
    @Nullable
    private PluginCall purchaseCall;
    @Nullable
    private String purchaseProductId;

    /** Called once the client is ready ({@code result == null}) or the connection failed. */
    private interface ReadyListener {
        void onReady(@Nullable BillingResult failure);
    }

    @Override
    public void load() {
        super.load();
        client = BillingClient.newBuilder(getContext())
            .setListener(this)
            .enablePendingPurchases(PendingPurchasesParams.newBuilder().enableOneTimeProducts().build())
            .build();
        connect();
    }

    @Override
    protected void handleOnDestroy() {
        mainHandler.removeCallbacks(reconnect);
        synchronized (this) {
            rejectPurchaseLocked("The app was closed.", CODE_CANCELLED);
        }
        if (client != null) {
            try {
                client.endConnection();
            } catch (RuntimeException ignored) {
                // Already closed.
            }
        }
        super.handleOnDestroy();
    }

    /* ------------------------------------------------------------------ */
    /* Connection                                                          */
    /* ------------------------------------------------------------------ */

    private void connect() {
        synchronized (this) {
            if (client == null || client.isReady() || connecting) {
                return;
            }
            connecting = true;
        }

        client.startConnection(
            new BillingClientStateListener() {
                @Override
                public void onBillingSetupFinished(@NonNull BillingResult result) {
                    List<ReadyListener> listeners;
                    synchronized (BillingPlugin.this) {
                        connecting = false;
                        if (result.getResponseCode() == BillingClient.BillingResponseCode.OK) {
                            reconnectDelayMs = RECONNECT_MIN_MS;
                        }
                        listeners = new ArrayList<>(waiting);
                        waiting.clear();
                    }
                    boolean ok = result.getResponseCode() == BillingClient.BillingResponseCode.OK;
                    for (ReadyListener listener : listeners) {
                        listener.onReady(ok ? null : result);
                    }
                }

                @Override
                public void onBillingServiceDisconnected() {
                    // Play's service went away (updated, killed…): try again with a growing delay.
                    long delay;
                    synchronized (BillingPlugin.this) {
                        connecting = false;
                        delay = reconnectDelayMs;
                        reconnectDelayMs = Math.min(RECONNECT_MAX_MS, reconnectDelayMs * 2);
                    }
                    mainHandler.removeCallbacks(reconnect);
                    mainHandler.postDelayed(reconnect, delay);
                }
            }
        );
    }

    /** Run {@code listener} now when the client is ready, or after the next (re)connection attempt. */
    private void whenReady(ReadyListener listener) {
        boolean ready;
        synchronized (this) {
            ready = client != null && client.isReady();
            if (!ready) {
                waiting.add(listener);
            }
        }
        if (ready) {
            listener.onReady(null);
            return;
        }
        // Not connected: (re)connect right away instead of waiting for the backoff timer.
        mainHandler.removeCallbacks(reconnect);
        mainHandler.post(reconnect);
    }

    /* ------------------------------------------------------------------ */
    /* Plugin methods                                                      */
    /* ------------------------------------------------------------------ */

    /** {available, reason?} — true when Google Play Billing can be used on this phone right now. */
    @PluginMethod
    public void isAvailable(PluginCall call) {
        whenReady(failure -> {
            JSObject result = new JSObject();
            boolean available = failure == null && client.isReady();
            result.put("available", available);
            if (!available) {
                result.put("reason", failure == null ? "not_connected" : reasonFor(failure));
            }
            resolveOnMain(call, result);
        });
    }

    /** {ids: [..]} → {products: [{productId, title, price, priceMicros, currency}]} from Play (INAPP). */
    @PluginMethod
    public void getProducts(PluginCall call) {
        List<String> ids = stringList(call.getArray("ids"));
        if (ids.isEmpty()) {
            JSObject result = new JSObject();
            result.put("products", new JSArray());
            call.resolve(result);
            return;
        }

        whenReady(failure -> {
            if (failure != null) {
                rejectOnMain(call, failure.getDebugMessage(), CODE_UNAVAILABLE);
                return;
            }
            queryProducts(ids, (result, details) -> {
                if (result.getResponseCode() != BillingClient.BillingResponseCode.OK) {
                    rejectOnMain(call, result.getDebugMessage(), codeFor(result));
                    return;
                }
                JSArray products = new JSArray();
                for (ProductDetails product : details) {
                    JSObject item = describe(product);
                    if (item != null) {
                        products.put(item);
                    }
                }
                JSObject payload = new JSObject();
                payload.put("products", products);
                resolveOnMain(call, payload);
            });
        });
    }

    /**
     * {productId, accountHash} → opens the Play purchase sheet. Resolves
     * {purchaseToken, orderId, productId, state: 'purchased'|'pending'} when Play reports the
     * purchase, or rejects with code cancelled | item_already_owned | unavailable | error.
     * The web app must then verify the token with the server before consuming it.
     */
    @PluginMethod
    public void purchase(PluginCall call) {
        String productId = call.getString("productId", "");
        String accountHash = call.getString("accountHash", "");
        if (productId == null || productId.isEmpty()) {
            call.reject("Missing product id.", CODE_ERROR);
            return;
        }

        synchronized (this) {
            if (purchaseCall != null) {
                call.reject("A purchase is already in progress.", CODE_ERROR);
                return;
            }
            purchaseCall = call;
            purchaseProductId = productId;
        }

        whenReady(failure -> {
            if (failure != null) {
                finishPurchase(null, failure.getDebugMessage(), CODE_UNAVAILABLE);
                return;
            }
            // Play needs the ProductDetails object (not just the id) to start the flow.
            queryProducts(Collections.singletonList(productId), (result, details) -> {
                if (result.getResponseCode() != BillingClient.BillingResponseCode.OK) {
                    finishPurchase(null, result.getDebugMessage(), codeFor(result));
                    return;
                }
                ProductDetails product = null;
                for (ProductDetails candidate : details) {
                    if (productId.equals(candidate.getProductId())) {
                        product = candidate;
                    }
                }
                if (product == null || product.getOneTimePurchaseOfferDetails() == null) {
                    finishPurchase(null, "This product is not available on Google Play.", CODE_UNAVAILABLE);
                    return;
                }
                launch(product, accountHash == null ? "" : accountHash);
            });
        });
    }

    /** launchBillingFlow must run on the main thread with a live activity. */
    private void launch(ProductDetails product, String accountHash) {
        mainHandler.post(() -> {
            Activity activity = getActivity();
            if (activity == null || activity.isFinishing()) {
                finishPurchase(null, "The app isn't on screen.", CODE_ERROR);
                return;
            }

            BillingFlowParams.Builder params = BillingFlowParams.newBuilder()
                .setProductDetailsParamsList(
                    Collections.singletonList(BillingFlowParams.ProductDetailsParams.newBuilder().setProductDetails(product).build())
                );
            if (!accountHash.isEmpty()) {
                // Binds the purchase to the signed-in account so the server can refuse tokens
                // that belong to someone else (obfuscatedExternalAccountId).
                params.setObfuscatedAccountId(accountHash);
            }

            BillingResult result;
            try {
                result = client.launchBillingFlow(activity, params.build());
            } catch (RuntimeException exception) {
                finishPurchase(null, exception.getMessage(), CODE_ERROR);
                return;
            }
            if (result.getResponseCode() != BillingClient.BillingResponseCode.OK) {
                finishPurchase(null, result.getDebugMessage(), codeFor(result));
            }
            // Otherwise the sheet is open; onPurchasesUpdated finishes the call.
        });
    }

    /** Play's answer to the purchase sheet, and purchases that complete outside a flow. */
    @Override
    public void onPurchasesUpdated(@NonNull BillingResult result, @Nullable List<Purchase> purchases) {
        PluginCall call;
        String productId;
        synchronized (this) {
            call = purchaseCall;
            productId = purchaseProductId;
        }

        if (call == null) {
            // No flow on screen: a PENDING purchase became PURCHASED, or it completed elsewhere.
            if (result.getResponseCode() == BillingClient.BillingResponseCode.OK && purchases != null && !purchases.isEmpty()) {
                JSObject data = new JSObject();
                data.put("purchases", describeAll(purchases));
                notifyListeners("purchaseUpdated", data, true);
            }
            return;
        }

        if (result.getResponseCode() != BillingClient.BillingResponseCode.OK) {
            finishPurchase(null, result.getDebugMessage(), codeFor(result));
            return;
        }

        Purchase match = null;
        if (purchases != null) {
            for (Purchase purchase : purchases) {
                if (productId != null && purchase.getProducts().contains(productId)) {
                    match = purchase;
                    break;
                }
            }
            if (match == null && !purchases.isEmpty()) {
                match = purchases.get(0);
            }
        }
        if (match == null) {
            finishPurchase(null, "Google Play returned no purchase.", CODE_ERROR);
            return;
        }
        finishPurchase(describe(match), null, null);
    }

    /** Unconsumed INAPP purchases (bought but not yet delivered by the server). */
    @PluginMethod
    public void getPendingPurchases(PluginCall call) {
        whenReady(failure -> {
            if (failure != null) {
                rejectOnMain(call, failure.getDebugMessage(), CODE_UNAVAILABLE);
                return;
            }
            client.queryPurchasesAsync(
                QueryPurchasesParams.newBuilder().setProductType(BillingClient.ProductType.INAPP).build(),
                (result, purchases) -> {
                    if (result.getResponseCode() != BillingClient.BillingResponseCode.OK) {
                        rejectOnMain(call, result.getDebugMessage(), codeFor(result));
                        return;
                    }
                    JSObject payload = new JSObject();
                    payload.put("purchases", describeAll(purchases));
                    resolveOnMain(call, payload);
                }
            );
        });
    }

    /**
     * {purchaseToken} → {ok}. Consuming tells Play the item was delivered (and lets the user buy
     * it again). Call it only after the server returned 200; a token Play no longer knows
     * (already consumed) counts as ok.
     */
    @PluginMethod
    public void consume(PluginCall call) {
        String token = call.getString("purchaseToken", "");
        if (token == null || token.isEmpty()) {
            call.reject("Missing purchase token.", CODE_ERROR);
            return;
        }

        whenReady(failure -> {
            if (failure != null) {
                rejectOnMain(call, failure.getDebugMessage(), CODE_UNAVAILABLE);
                return;
            }
            client.consumeAsync(
                ConsumeParams.newBuilder().setPurchaseToken(token).build(),
                (result, purchaseToken) -> {
                    int code = result.getResponseCode();
                    boolean ok = code == BillingClient.BillingResponseCode.OK || code == BillingClient.BillingResponseCode.ITEM_NOT_OWNED;
                    JSObject payload = new JSObject();
                    payload.put("ok", ok);
                    if (!ok) {
                        payload.put("reason", codeFor(result));
                        Logger.warn(TAG, "consume failed: " + result.getDebugMessage());
                    }
                    resolveOnMain(call, payload);
                }
            );
        });
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private interface ProductsListener {
        void onProducts(BillingResult result, List<ProductDetails> details);
    }

    private void queryProducts(List<String> ids, ProductsListener listener) {
        List<QueryProductDetailsParams.Product> products = new ArrayList<>();
        for (String id : ids) {
            products.add(
                QueryProductDetailsParams.Product.newBuilder()
                    .setProductId(id)
                    .setProductType(BillingClient.ProductType.INAPP)
                    .build()
            );
        }
        client.queryProductDetailsAsync(
            QueryProductDetailsParams.newBuilder().setProductList(products).build(),
            (result, details) -> executor.execute(() -> listener.onProducts(result, details == null ? new ArrayList<>() : details))
        );
    }

    /** Resolve or reject the purchase flow on screen (once) and free the slot for the next one. */
    private void finishPurchase(@Nullable JSObject purchase, @Nullable String message, @Nullable String code) {
        PluginCall call;
        synchronized (this) {
            call = purchaseCall;
            purchaseCall = null;
            purchaseProductId = null;
        }
        if (call == null) {
            return;
        }
        if (purchase != null) {
            resolveOnMain(call, purchase);
        } else {
            rejectOnMain(call, message == null || message.isEmpty() ? "Purchase failed." : message, code == null ? CODE_ERROR : code);
        }
    }

    /** Must be called with {@code this} held. */
    private void rejectPurchaseLocked(String message, String code) {
        PluginCall call = purchaseCall;
        purchaseCall = null;
        purchaseProductId = null;
        if (call != null) {
            rejectOnMain(call, message, code);
        }
    }

    private void resolveOnMain(PluginCall call, JSObject data) {
        mainHandler.post(() -> call.resolve(data));
    }

    private void rejectOnMain(PluginCall call, String message, String code) {
        mainHandler.post(() -> call.reject(message == null ? "Google Play error." : message, code));
    }

    @Nullable
    private static JSObject describe(ProductDetails product) {
        ProductDetails.OneTimePurchaseOfferDetails offer = product.getOneTimePurchaseOfferDetails();
        if (offer == null) {
            return null; // Not a one-time product (a subscription): not sold this way.
        }
        JSObject item = new JSObject();
        item.put("productId", product.getProductId());
        // getName() is the product name alone; getTitle() would append "(App name)".
        item.put("title", product.getName());
        item.put("description", product.getDescription());
        item.put("price", offer.getFormattedPrice());
        item.put("priceMicros", offer.getPriceAmountMicros());
        item.put("currency", offer.getPriceCurrencyCode());
        return item;
    }

    private static JSObject describe(Purchase purchase) {
        JSObject item = new JSObject();
        item.put("purchaseToken", purchase.getPurchaseToken());
        item.put("orderId", purchase.getOrderId()); // null while PENDING
        item.put("productId", purchase.getProducts().isEmpty() ? "" : purchase.getProducts().get(0));
        item.put("state", purchase.getPurchaseState() == Purchase.PurchaseState.PURCHASED ? "purchased" : "pending");
        item.put("acknowledged", purchase.isAcknowledged());
        return item;
    }

    private static JSArray describeAll(@Nullable List<Purchase> purchases) {
        JSArray list = new JSArray();
        if (purchases != null) {
            for (Purchase purchase : purchases) {
                list.put(describe(purchase));
            }
        }
        return list;
    }

    private static List<String> stringList(@Nullable JSArray array) {
        List<String> values = new ArrayList<>();
        if (array == null) {
            return values;
        }
        try {
            for (Object value : array.toList()) {
                String id = value == null ? "" : String.valueOf(value).trim();
                if (!id.isEmpty() && !values.contains(id)) {
                    values.add(id);
                }
            }
        } catch (JSONException ignored) {
            // Treated as an empty list.
        }
        return values;
    }

    /** JS error code for a Play response. */
    private static String codeFor(BillingResult result) {
        switch (result.getResponseCode()) {
            case BillingClient.BillingResponseCode.USER_CANCELED:
                return CODE_CANCELLED;
            case BillingClient.BillingResponseCode.ITEM_ALREADY_OWNED:
                return CODE_ALREADY_OWNED;
            case BillingClient.BillingResponseCode.SERVICE_UNAVAILABLE:
            case BillingClient.BillingResponseCode.BILLING_UNAVAILABLE:
            case BillingClient.BillingResponseCode.ITEM_UNAVAILABLE:
            case BillingClient.BillingResponseCode.SERVICE_DISCONNECTED:
            case BillingClient.BillingResponseCode.NETWORK_ERROR:
            case BillingClient.BillingResponseCode.FEATURE_NOT_SUPPORTED:
                return CODE_UNAVAILABLE;
            default:
                return CODE_ERROR;
        }
    }

    /** Short reason for isAvailable(): why Play Billing cannot be used right now. */
    private static String reasonFor(BillingResult result) {
        switch (result.getResponseCode()) {
            case BillingClient.BillingResponseCode.BILLING_UNAVAILABLE:
                return "billing_unavailable"; // No Play Store / not signed in / unsupported country
            case BillingClient.BillingResponseCode.SERVICE_UNAVAILABLE:
            case BillingClient.BillingResponseCode.NETWORK_ERROR:
                return "network";
            case BillingClient.BillingResponseCode.SERVICE_DISCONNECTED:
                return "disconnected";
            case BillingClient.BillingResponseCode.FEATURE_NOT_SUPPORTED:
                return "unsupported";
            default:
                return "error";
        }
    }
}
