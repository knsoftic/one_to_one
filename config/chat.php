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
            'extensions' => ['jpg', 'jpeg', 'png'],
            'max_kb' => (int) env('CHAT_MAX_IMAGE_KB', 5120),
            'max_dimension' => 8000,
            'thumbnail_width' => 480,
        ],

        'document' => [
            'extensions' => ['pdf', 'doc', 'docx'],
            // Detected content types (libmagic reports Word files in several ways).
            'mimetypes' => [
                'application/pdf', 'application/x-pdf',
                'application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/CDFV2', 'application/x-ole-storage',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip',
            ],
            'max_kb' => (int) env('CHAT_MAX_DOCUMENT_KB', 10240),
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

    'calls' => [
        'enabled' => (bool) env('CHAT_CALLS_ENABLED', true),
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

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'username' => env('ADMIN_USERNAME', 'admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'phone' => env('ADMIN_PHONE', '+10000000000'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
