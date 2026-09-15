package com.hunario.chat;

import android.content.ContentResolver;
import android.content.Context;
import android.content.Intent;
import android.database.Cursor;
import android.net.Uri;
import android.provider.OpenableColumns;
import android.webkit.MimeTypeMap;
import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.io.RandomAccessFile;
import java.util.ArrayList;
import java.util.List;
import java.util.UUID;
import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

/**
 * X2 — "Share → One2One Chat" from other apps: text, links, photos, videos and files.
 * The intent is kept until the chat page asks for it; files are copied into the app's
 * cache (where the page reads them in pieces) and removed once sent or dismissed.
 */
final class ShareInbox {

    static final int MAX_FILES = 10;
    static final long MAX_FILE_BYTES = 100L * 1024 * 1024;
    private static final String FOLDER = "shared";

    private static String pendingId;
    private static String text = "";
    private static final List<Uri> uris = new ArrayList<>();
    private static JSONArray files;

    private ShareInbox() {}

    static boolean isShare(Intent intent) {
        if (intent == null) {
            return false;
        }
        String action = intent.getAction();
        return Intent.ACTION_SEND.equals(action) || Intent.ACTION_SEND_MULTIPLE.equals(action);
    }

    /** Keep a share intent; returns true when it was one. */
    static synchronized boolean capture(Context context, Intent intent) {
        if (!isShare(intent)) {
            return false;
        }

        clear(context);
        pendingId = UUID.randomUUID().toString();

        String subject = intent.getStringExtra(Intent.EXTRA_SUBJECT);
        CharSequence body = intent.getCharSequenceExtra(Intent.EXTRA_TEXT);
        StringBuilder builder = new StringBuilder();
        if (subject != null && !subject.trim().isEmpty() && (body == null || !body.toString().contains(subject))) {
            builder.append(subject.trim());
        }
        if (body != null && body.toString().trim().length() > 0) {
            if (builder.length() > 0) {
                builder.append('\n');
            }
            builder.append(body.toString().trim());
        }
        text = builder.toString();

        if (Intent.ACTION_SEND_MULTIPLE.equals(intent.getAction())) {
            ArrayList<Uri> streams = intent.getParcelableArrayListExtra(Intent.EXTRA_STREAM);
            if (streams != null) {
                for (Uri uri : streams) {
                    if (uri != null && uris.size() < MAX_FILES) {
                        uris.add(uri);
                    }
                }
            }
        } else {
            Uri uri = intent.getParcelableExtra(Intent.EXTRA_STREAM);
            if (uri != null) {
                uris.add(uri);
            }
        }

        // Handled: a later start of the activity must not share it again.
        intent.setAction(Intent.ACTION_MAIN);
        intent.removeExtra(Intent.EXTRA_STREAM);
        intent.removeExtra(Intent.EXTRA_TEXT);
        return true;
    }

    /**
     * What was shared: {id, text, files: [{index, name, mime, size, skipped}]}; copies the files
     * the first time (call off the main thread). Empty object when nothing is waiting.
     */
    static synchronized JSONObject describe(Context context) throws JSONException {
        JSONObject result = new JSONObject();
        if (pendingId == null) {
            return result;
        }

        if (files == null) {
            files = new JSONArray();
            File folder = folder(context);
            ContentResolver resolver = context.getContentResolver();

            for (int i = 0; i < uris.size(); i++) {
                Uri uri = uris.get(i);
                JSONObject file = new JSONObject();
                String mime = resolver.getType(uri);
                String name = displayName(resolver, uri);
                if (mime == null) {
                    String extension = MimeTypeMap.getFileExtensionFromUrl(name);
                    mime = extension == null ? null : MimeTypeMap.getSingleton().getMimeTypeFromExtension(extension.toLowerCase());
                }
                file.put("index", i);
                file.put("name", name);
                file.put("mime", mime == null ? "application/octet-stream" : mime);

                File target = new File(folder, i + "-" + UUID.randomUUID());
                long size = copy(resolver, uri, target);
                file.put("size", Math.max(0, size));
                file.put("skipped", size < 0);
                file.put("path", size < 0 ? "" : target.getAbsolutePath());
                files.put(file);
            }
        }

        result.put("id", pendingId);
        result.put("text", text);
        JSONArray visible = new JSONArray();
        for (int i = 0; i < files.length(); i++) {
            JSONObject copy = new JSONObject(files.getJSONObject(i).toString());
            copy.remove("path");
            visible.put(copy);
        }
        result.put("files", visible);
        return result;
    }

    /** Bytes of one copied file, from offset. */
    static synchronized byte[] read(int index, long offset, int length) throws IOException, JSONException {
        if (files == null || index < 0 || index >= files.length()) {
            throw new IOException("No such shared file.");
        }
        String path = files.getJSONObject(index).optString("path", "");
        if (path.isEmpty()) {
            throw new IOException("This file could not be read.");
        }

        try (RandomAccessFile file = new RandomAccessFile(path, "r")) {
            long remaining = Math.max(0, file.length() - offset);
            byte[] buffer = new byte[(int) Math.min(length, remaining)];
            file.seek(offset);
            file.readFully(buffer);
            return buffer;
        }
    }

    static synchronized boolean hasPending() {
        return pendingId != null;
    }

    static synchronized void clear(Context context) {
        pendingId = null;
        text = "";
        uris.clear();
        files = null;
        File folder = new File(context.getCacheDir(), FOLDER);
        File[] children = folder.listFiles();
        if (children != null) {
            for (File child : children) {
                //noinspection ResultOfMethodCallIgnored
                child.delete();
            }
        }
    }

    private static File folder(Context context) {
        File folder = new File(context.getCacheDir(), FOLDER);
        //noinspection ResultOfMethodCallIgnored
        folder.mkdirs();
        return folder;
    }

    private static String displayName(ContentResolver resolver, Uri uri) {
        String name = null;
        try (Cursor cursor = resolver.query(uri, new String[] { OpenableColumns.DISPLAY_NAME }, null, null, null)) {
            if (cursor != null && cursor.moveToFirst()) {
                name = cursor.getString(0);
            }
        } catch (RuntimeException ignored) {
            // Some apps don't answer: use the last part of the address.
        }
        if (name == null || name.trim().isEmpty()) {
            name = uri.getLastPathSegment();
        }
        return name == null || name.trim().isEmpty() ? "shared-file" : name.replaceAll("[\\\\/:*?\"<>|]", "_");
    }

    /** Copy into the cache; -1 when unreadable or too large. */
    private static long copy(ContentResolver resolver, Uri uri, File target) {
        long total = 0;
        try (InputStream in = resolver.openInputStream(uri); OutputStream out = new FileOutputStream(target)) {
            if (in == null) {
                return -1;
            }
            byte[] buffer = new byte[64 * 1024];
            int read;
            while ((read = in.read(buffer)) != -1) {
                total += read;
                if (total > MAX_FILE_BYTES) {
                    //noinspection ResultOfMethodCallIgnored
                    target.delete();
                    return -1;
                }
                out.write(buffer, 0, read);
            }
            return total;
        } catch (IOException | RuntimeException exception) {
            //noinspection ResultOfMethodCallIgnored
            target.delete();
            return -1;
        }
    }
}
