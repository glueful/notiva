<?php

declare(strict_types=1);

/*
 * Notiva (Push Notifications) — Extension Configuration
 *
 * This file contains Notiva-specific configuration. Core app-level
 * secrets and credentials can be exposed here via env(...) as needed.
 */

return [
    // Default driver selection order if multiple tokens are available
    'default_order' => [
        'fcm',    // Firebase Cloud Messaging
        'apns',   // Apple Push Notification service
        'webpush' // Browser Web Push (VAPID)
    ],

    // Per-driver configuration (keep secrets in env)
    'drivers' => [
        'fcm' => [
            'enabled' => (bool) env('NOTIVA_FCM_ENABLED', true),
            // HTTP v1: service account JSON (path or raw JSON)
            'credentials' => env('NOTIVA_FCM_CREDENTIALS', null),
            // HTTP v1: GCP project ID
            'project' => env('NOTIVA_FCM_PROJECT', null),
        ],
        'apns' => [
            'enabled' => (bool) env('NOTIVA_APNS_ENABLED', true),
            // Either a .p8 key with keyId/teamId, or certificate bundle
            'key_id' => env('NOTIVA_APNS_KEY_ID', null),
            'team_id' => env('NOTIVA_APNS_TEAM_ID', null),
            'app_bundle_id' => env('NOTIVA_APNS_BUNDLE_ID', null),
            'p8_path' => env('NOTIVA_APNS_P8_PATH', null),
            'certificate' => env('NOTIVA_APNS_CERT', null),
            'passphrase' => env('NOTIVA_APNS_PASSPHRASE', null),
            'sandbox' => (bool) env('NOTIVA_APNS_SANDBOX', true),
        ],
        'webpush' => [
            'enabled' => (bool) env('NOTIVA_WEBPUSH_ENABLED', true),
            'vapid' => [
                'subject' => env('NOTIVA_VAPID_SUBJECT', null),
                'public_key' => env('NOTIVA_VAPID_PUBLIC_KEY', null),
                'private_key' => env('NOTIVA_VAPID_PRIVATE_KEY', null),
            ],
        ],
    ],

    // Basic feature flags
    'features' => [
        'track_delivery' => (bool) env('NOTIVA_TRACK_DELIVERY', false),
        'debug' => (bool) env('NOTIVA_DEBUG', false),
    ],
];
