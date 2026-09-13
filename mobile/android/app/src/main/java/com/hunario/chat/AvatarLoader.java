package com.hunario.chat;

import android.content.Context;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.BitmapShader;
import android.graphics.Canvas;
import android.graphics.Matrix;
import android.graphics.Paint;
import android.graphics.Shader;
import android.graphics.Typeface;
import android.net.Uri;
import androidx.core.graphics.ColorUtils;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.Locale;
import java.util.concurrent.TimeUnit;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;
import okhttp3.ResponseBody;

/**
 * Round sender photos for notifications: downloaded from our own server and
 * cached for a week, or a coloured circle with initials (same colours as the web app).
 */
final class AvatarLoader {

    private static final int SIZE = 192;
    private static final long MAX_AGE_MS = 7L * 24 * 60 * 60 * 1000;
    private static final long MAX_BYTES = 5L * 1024 * 1024;

    private AvatarLoader() {}

    /** Must be called off the main thread. */
    static Bitmap load(Context context, NotificationSettings settings, ChatNotification message) {
        Bitmap photo = null;
        if (!message.avatarUrl.isEmpty() && isOwnServer(message.avatarUrl, settings.serverUrl)) {
            photo = cachedOrDownloaded(context, message.avatarUrl);
        }
        return photo != null ? photo : initials(message.initials, message.avatarHue);
    }

    private static Bitmap cachedOrDownloaded(Context context, String url) {
        File directory = new File(context.getCacheDir(), "avatars");
        File file = new File(directory, sha1(url) + ".png");

        if (file.exists() && System.currentTimeMillis() - file.lastModified() < MAX_AGE_MS) {
            Bitmap cached = BitmapFactory.decodeFile(file.getPath());
            if (cached != null) {
                return cached;
            }
        }

        OkHttpClient client = NotificationFeed.http().newBuilder().callTimeout(6, TimeUnit.SECONDS).build();
        try (Response response = client.newCall(new Request.Builder().url(url).build()).execute()) {
            ResponseBody body = response.body();
            if (!response.isSuccessful() || body == null || body.contentLength() > MAX_BYTES) {
                return null;
            }

            byte[] bytes = body.bytes();
            Bitmap decoded = decodeSampled(bytes);
            if (decoded == null) {
                return null;
            }

            Bitmap round = circle(decoded);
            if (directory.exists() || directory.mkdirs()) {
                try (FileOutputStream out = new FileOutputStream(file)) {
                    round.compress(Bitmap.CompressFormat.PNG, 100, out);
                }
            }
            return round;
        } catch (IOException | RuntimeException exception) {
            return null;
        }
    }

    private static Bitmap decodeSampled(byte[] bytes) {
        BitmapFactory.Options bounds = new BitmapFactory.Options();
        bounds.inJustDecodeBounds = true;
        BitmapFactory.decodeByteArray(bytes, 0, bytes.length, bounds);

        int sample = 1;
        while (bounds.outWidth / (sample * 2) >= SIZE && bounds.outHeight / (sample * 2) >= SIZE) {
            sample *= 2;
        }

        BitmapFactory.Options options = new BitmapFactory.Options();
        options.inSampleSize = sample;
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.length, options);
    }

    private static Bitmap circle(Bitmap source) {
        Bitmap output = Bitmap.createBitmap(SIZE, SIZE, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(output);

        float scale = Math.max((float) SIZE / source.getWidth(), (float) SIZE / source.getHeight());
        Matrix matrix = new Matrix();
        matrix.setScale(scale, scale);
        matrix.postTranslate((SIZE - source.getWidth() * scale) / 2f, (SIZE - source.getHeight() * scale) / 2f);

        BitmapShader shader = new BitmapShader(source, Shader.TileMode.CLAMP, Shader.TileMode.CLAMP);
        shader.setLocalMatrix(matrix);

        Paint paint = new Paint(Paint.ANTI_ALIAS_FLAG | Paint.FILTER_BITMAP_FLAG);
        paint.setShader(shader);
        canvas.drawCircle(SIZE / 2f, SIZE / 2f, SIZE / 2f, paint);

        return output;
    }

    private static Bitmap initials(String initials, int hue) {
        Bitmap output = Bitmap.createBitmap(SIZE, SIZE, Bitmap.Config.ARGB_8888);
        Canvas canvas = new Canvas(output);

        Paint background = new Paint(Paint.ANTI_ALIAS_FLAG);
        background.setColor(ColorUtils.HSLToColor(new float[] { ((hue % 360) + 360) % 360, 0.55f, 0.48f }));
        canvas.drawCircle(SIZE / 2f, SIZE / 2f, SIZE / 2f, background);

        String text = initials == null || initials.isEmpty() ? "?" : initials.toUpperCase(Locale.ROOT);
        if (text.length() > 2) {
            text = text.substring(0, 2);
        }

        Paint letters = new Paint(Paint.ANTI_ALIAS_FLAG);
        letters.setColor(0xFFFFFFFF);
        letters.setTextSize(SIZE * 0.4f);
        letters.setTypeface(Typeface.create(Typeface.SANS_SERIF, Typeface.BOLD));
        letters.setTextAlign(Paint.Align.CENTER);

        Paint.FontMetrics metrics = letters.getFontMetrics();
        float baseline = SIZE / 2f - (metrics.ascent + metrics.descent) / 2f;
        canvas.drawText(text, SIZE / 2f, baseline, letters);

        return output;
    }

    private static boolean isOwnServer(String url, String serverUrl) {
        Uri uri = Uri.parse(url);
        Uri server = Uri.parse(serverUrl);
        return uri.getHost() != null && uri.getHost().equalsIgnoreCase(server.getHost());
    }

    private static String sha1(String value) {
        try {
            byte[] digest = MessageDigest.getInstance("SHA-1").digest(value.getBytes());
            StringBuilder hex = new StringBuilder();
            for (byte b : digest) {
                hex.append(String.format(Locale.ROOT, "%02x", b));
            }
            return hex.toString();
        } catch (NoSuchAlgorithmException exception) {
            return String.valueOf(value.hashCode());
        }
    }
}
