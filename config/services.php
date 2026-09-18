<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS (login codes and changing the number, Phase 7)
    |--------------------------------------------------------------------------
    |
    | "log" writes each SMS to storage/logs (only for testing: in production
    | phone login stays hidden until "twilio" or "http" is set up). "http"
    | works with most SMS gateways that take the number and text in a web request.
    |
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),

        'twilio' => [
            'sid' => env('TWILIO_ACCOUNT_SID'),
            'token' => env('TWILIO_AUTH_TOKEN'),
            // A sender number (+1…) or a Messaging Service SID (MG…).
            'from' => env('TWILIO_FROM'),
        ],

        'http' => [
            'url' => env('SMS_HTTP_URL'),
            'method' => env('SMS_HTTP_METHOD', 'post'),
            // form, json or query
            'format' => env('SMS_HTTP_FORMAT', 'form'),
            'to_field' => env('SMS_HTTP_TO_FIELD', 'to'),
            'message_field' => env('SMS_HTTP_MESSAGE_FIELD', 'message'),
            // plus = +923001234567, digits = 923001234567
            'phone_format' => env('SMS_HTTP_PHONE_FORMAT', 'plus'),
            // Extra fields and headers written like a query string: "api_key=abc&sender=MyApp"
            'params' => env('SMS_HTTP_PARAMS'),
            'headers' => env('SMS_HTTP_HEADERS'),
            'bearer' => env('SMS_HTTP_BEARER'),
            // Optional text the gateway's reply must contain to count as sent.
            'success_text' => env('SMS_HTTP_SUCCESS_TEXT'),
        ],
    ],

    /*
    | Payments (Y2). Set from Admin → App settings → Paid features; these are the .env fallbacks.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'secret' => env('PAYPAL_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    'play' => [
        'package_name' => env('PLAY_PACKAGE_NAME', 'com.hunario.chat'),
        // The JSON of a Google Cloud service account with access to the Play Developer API.
        'service_account' => env('PLAY_SERVICE_ACCOUNT_JSON'),
    ],

];
