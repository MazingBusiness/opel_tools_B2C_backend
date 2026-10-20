<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'smsalert' => [
        'api_key' => env('SMSALERT_API_KEY'),
        'sender' => env('SMSALERT_SENDER'),
        'otp_text' => env('SMSALERT_OTP_TEXT', 'Your OPEL login code is {code}. It expires in 2 minutes.'),
    ],

    // Meta WhatsApp Cloud API — phone OTP delivery (falls back to SMS Alert on API failure).
    'whatsapp' => [
        'otp_enabled' => (bool) env('WHATSAPP_OTP_ENABLED', false), // kill switch
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE', 'login_registration_otp'),
        'otp_language' => env('WHATSAPP_OTP_LANGUAGE', 'en'),
        // Authentication templates carry a copy-code / one-tap OTP button that needs the
        // code as a URL-button parameter. Set false only if the approved template has none.
        'otp_button' => (bool) env('WHATSAPP_OTP_BUTTON', true),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 8),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

];
