<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    // Number of messages loaded initially and per "load older" request.
    'messages_per_page' => (int) env('CHAT_MESSAGES_PER_PAGE', 30),

    'max_message_length' => 5000,

    // Title, description and image under the first link of a message (fetched by the server).
    'link_previews' => [
        'enabled' => (bool) env('CHAT_LINK_PREVIEWS', true),
    ],

    // GIF search (Tenor). Off without an API key; GIF files can always be sent.
    'gifs' => [
        'tenor_key' => env('CHAT_TENOR_KEY'),
        'content_filter' => env('CHAT_TENOR_CONTENT_FILTER', 'medium'),
        'max_kb' => 8192,
    ],

    // 0 = no time limit.
    'edit_window_minutes' => (int) env('CHAT_EDIT_WINDOW_MINUTES', 0),
    'delete_for_everyone_window_minutes' => (int) env('CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES', 0),

    /*
    |--------------------------------------------------------------------------
    | Presence & realtime
    |--------------------------------------------------------------------------
    */

    // A user is considered online if their last activity is within this window.
    'online_threshold_seconds' => (int) env('CHAT_ONLINE_THRESHOLD_SECONDS', 120),

    // Client heartbeat interval (keeps last_seen fresh while a tab is open).
    'heartbeat_interval_seconds' => 45,

    // How long a "typing" signal stays valid without a new ping.
    'typing_ttl_seconds' => 5,

    // AJAX polling interval used when the WebSocket connection is unavailable.
    'polling_interval_ms' => (int) env('CHAT_POLLING_INTERVAL_MS', 4000),

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Chat attachments are stored on the private "chat" disk and are only ever
    | served through an authorized route. Avatars live on the "public" disk.
    |
    */

    'uploads' => [
        'disk' => 'chat',

        'image' => [
            // GIFs are stored unchanged so they stay animated.
            'extensions' => ['jpg', 'jpeg', 'png', 'gif'],
            'max_kb' => (int) env('CHAT_MAX_IMAGE_KB', 5120),
            'max_dimension' => 8000,
            'thumbnail_width' => 480,
            // Longest edge kept on the server: standard photos like WhatsApp, "HD" when the sender picks it.
            'max_edge' => 1600,
            'hd_max_edge' => 3072,
        ],

        'document' => [
            // Extension => content types libmagic may report for it; a file must match its own extension's list.
            // Programs and installers (exe, apk, bat, js…) are never accepted.
            'types' => [
                'pdf' => ['application/pdf', 'application/x-pdf'],
                'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
                'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
                'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
                'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
                'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
                'rtf' => ['text/rtf', 'application/rtf'],
                'txt' => ['text/plain'],
                'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
                'rar' => ['application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'],
                '7z' => ['application/x-7z-compressed'],
                'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
                'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
            ],
            'extensions' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'zip', 'rar', '7z', 'mp3', 'm4a'],
            'max_kb' => (int) env('CHAT_MAX_DOCUMENT_KB', 10240),
        ],

        // Played in the browser: MP4 (H.264) everywhere, WebM on Android/Chrome, MOV/3GP from phones.
        // Keep max_kb below PHP's upload_max_filesize / post_max_size and Nginx client_max_body_size.
        'video' => [
            'extensions' => ['mp4', 'm4v', 'webm', 'mov', '3gp'],
            'mimetypes' => [
                'video/mp4', 'video/x-m4v', 'application/mp4', 'video/webm', 'video/quicktime',
                'video/3gpp', 'video/3gpp2', 'audio/mp4', 'audio/3gpp',
            ],
            'max_kb' => (int) env('CHAT_MAX_VIDEO_KB', 16384),
            'max_seconds' => 3 * 3600,
            // Poster frame captured by the sender's browser.
            'thumbnail_max_kb' => 1024,
        ],

        'voice' => [
            'extensions' => ['webm', 'weba', 'ogg', 'oga', 'mp3', 'm4a', 'mp4', 'wav'],
            'mimetypes' => [
                'audio/webm', 'video/webm', 'audio/ogg', 'application/ogg', 'video/ogg',
                'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'video/mp4',
                'audio/wav', 'audio/x-wav', 'audio/wave',
            ],
            'max_kb' => (int) env('CHAT_MAX_VOICE_KB', 10240),
            'max_seconds' => 300,
        ],

        'sticker' => [
            'max_kb' => 2048,
        ],

        'avatar' => [
            'disk' => 'public',
            'directory' => 'avatars',
            'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'max_kb' => (int) env('CHAT_MAX_AVATAR_KB', 2048),
            'size' => 256,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    */

    'reserved_usernames' => [
        'admin', 'administrator', 'root', 'system', 'support', 'help', 'moderator',
        'staff', 'official', 'security', 'api', 'www', 'mail', 'null', 'undefined',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */

    'security' => [
        // Nonce-based Content-Security-Policy on HTML pages (disabled automatically with `npm run dev`).
        'csp' => (bool) env('CHAT_CSP', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile app notifications (no Firebase)
    |--------------------------------------------------------------------------
    |
    | The Android app keeps its own background connection: it listens on the
    | user's private Reverb channel and falls back to polling the server.
    |
    */

    'mobile' => [
        // How often the app checks for new messages while the WebSocket is unavailable.
        'poll_interval_seconds' => (int) env('CHAT_MOBILE_POLL_SECONDS', 60),
        // Show the message text in phone notifications (false = "New message").
        'show_preview' => (bool) env('CHAT_MOBILE_NOTIFICATION_PREVIEW', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push notifications (Firebase Cloud Messaging, HTTP v1 API)
    |--------------------------------------------------------------------------
    |
    | Enabled when FCM_CREDENTIALS points to a Firebase service-account JSON
    | file (keep it outside public/, e.g. storage/app/private). Phones without
    | Google Play services keep using the app's own background connection.
    |
    */

    'push' => [
        'credentials' => env('FCM_CREDENTIALS'),
        // Optional: read from the service-account file when empty.
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Voice & video calls (WebRTC)
    |--------------------------------------------------------------------------
    |
    | Media flows directly between the two devices. STUN lets most networks
    | connect; a TURN server (e.g. coturn) relays calls on strict networks such
    | as many mobile carriers. With CHAT_CALL_TURN_SECRET (coturn
    | "use-auth-secret") every user gets short-lived TURN credentials.
    |
    */

    'groups' => [
        // Most people in one group chat (Phase 4).
        'max_members' => (int) env('CHAT_GROUP_MAX_MEMBERS', 256),
        // Most people in one broadcast list (G9).
        'max_broadcast_recipients' => (int) env('CHAT_BROADCAST_MAX_RECIPIENTS', 256),
    ],

    'calls' => [
        'enabled' => (bool) env('CHAT_CALLS_ENABLED', true),
        // Group calls (K6): everyone connects to everyone, so keep it small.
        'max_group_participants' => (int) env('CHAT_CALL_MAX_GROUP', 4),
        'ring_timeout_seconds' => (int) env('CHAT_CALL_RING_SECONDS', 45),
        // An ongoing call is closed when neither device reported in for this long.
        'stale_after_seconds' => (int) env('CHAT_CALL_STALE_SECONDS', 90),
        'heartbeat_seconds' => 20,
        'stun_urls' => env('CHAT_CALL_STUN_URLS', 'stun:stun.l.google.com:19302,stun:stun1.l.google.com:19302'),
        'turn_urls' => env('CHAT_CALL_TURN_URLS'),
        'turn_secret' => env('CHAT_CALL_TURN_SECRET'),
        'turn_username' => env('CHAT_CALL_TURN_USERNAME'),
        'turn_password' => env('CHAT_CALL_TURN_PASSWORD'),
        'turn_ttl_seconds' => (int) env('CHAT_CALL_TURN_TTL', 43200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Invite friends (C8)
    |--------------------------------------------------------------------------
    |
    | The link sent to friends who are not on the app yet. Empty = the sign-up
    | page of this site; set it to e.g. an APK or Play Store link.
    |
    */

    'invite' => [
        'url' => env('CHAT_INVITE_URL'),
    ],

    // Chat lock (C9): how long "Locked chats" stay open after the secret code
    // was entered (extended while they are in use).
    'lock' => [
        'unlock_minutes' => (int) env('CHAT_LOCK_UNLOCK_MINUTES', 10),
    ],

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'phone' => env('ADMIN_PHONE', '+10000000000'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
