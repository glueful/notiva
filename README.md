# Notiva (Push Notifications) for Glueful

## Overview

Notiva provides a unified, multi-channel push notification layer for the Glueful Framework. It supports FCM (HTTP v1), direct APNs, and Web Push (VAPID), with a clean routing model, optional per‑device registration endpoints, and configuration via environment variables.

## Features

- ✅ Unified push API (FCM, APNs, Web Push)
- ✅ FCM HTTP v1 with Android options
- ✅ Direct APNs via pushok (token or certificate)
- ✅ Web Push via VAPID (minishlink/web-push)
- ✅ Device registry: register, list, revoke/delete
- ✅ Config-driven behavior (env + merged config)
- ✅ Secure, typed responses and logging

## Requirements

- PHP 8.3+
- Glueful Framework 1.22.0+
- OpenSSL PHP extension
- Optional libraries:
  - FCM: built-in over Glueful HTTP client (no extra package)
  - APNs: `edamov/pushok`
  - Web Push: `minishlink/web-push`

## Installation

```bash
composer require glueful/notiva

# Rebuild extension cache
php glueful extensions:cache

# Enable in development
php glueful extensions:enable Notiva

# Run migrations for push_devices
php glueful migrate run

# Verify
php glueful extensions:list
php glueful extensions:info Notiva
```

## Verify Installation

Check discovery and provider wiring:

```bash
php glueful extensions:list
php glueful extensions:info Notiva
php glueful extensions:why Glueful\\Extensions\\Notiva\\NotivaServiceProvider
```

Run database migrations (if not auto-run):

```bash
php glueful migrate run
```

Quick endpoint checks (replace placeholders):

```bash
API_BASE=http://localhost:8000
TOKEN="<YOUR_BEARER_TOKEN>"
USER_UUID="<USER_UUID>"

# 1) Register an FCM device
curl -s -X POST "$API_BASE/notiva/devices" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_uuid": "'$USER_UUID'",
    "provider": "fcm",
    "platform": "android",
    "device_token": "fcm-token-example"
  }' | jq .

# 2) List devices
curl -s "$API_BASE/notiva/devices?user_uuid=$USER_UUID" \
  -H "Authorization: Bearer $TOKEN" | jq .

# 3) Unregister device by provider+token (soft revoke)
curl -s -X DELETE "$API_BASE/notiva/devices" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_uuid": "'$USER_UUID'",
    "provider": "fcm",
    "device_token": "fcm-token-example"
  }' | jq .

# 4) Unregister device by UUID (hard delete)
DEVICE_UUID="<DEVICE_UUID_FROM_LIST>"
curl -s -X DELETE "$API_BASE/notiva/devices" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "user_uuid": "'$USER_UUID'",
    "uuid": "'$DEVICE_UUID'",
    "force": true
  }' | jq .
```

## Getting Started
- Require the extension in your Glueful app and register the provider `Glueful\Extensions\Notiva\NotivaServiceProvider`.
- Configure `config/notiva.php` (published in the extension) or set the relevant env vars:
  - `NOTIVA_FCM_ENABLED`
  - HTTP v1 only: `NOTIVA_FCM_CREDENTIALS` (service account JSON or path) and `NOTIVA_FCM_PROJECT`
  - `NOTIVA_APNS_ENABLED`, `NOTIVA_APNS_P8_PATH`, `NOTIVA_APNS_KEY_ID`, `NOTIVA_APNS_TEAM_ID`, `NOTIVA_APNS_BUNDLE_ID`
  - `NOTIVA_WEBPUSH_ENABLED`, `NOTIVA_VAPID_PUBLIC_KEY`, `NOTIVA_VAPID_PRIVATE_KEY`, `NOTIVA_VAPID_SUBJECT`

## Endpoints
- Base prefix: `/notiva`
- All endpoints require auth and apply rate limiting.

1) Register device
- `POST /notiva/devices` (60/min)
- Body (JSON or form): `user_uuid` (required), `provider` (`fcm|apns|webpush`, required), `platform`, `device_token` (for fcm/apns), `subscription` (for webpush), `device_id`, `app_id`, `bundle_id`, `locale`, `timezone`

2) List devices
- `GET /notiva/devices` (100/min)
- Query: `user_uuid` (required), `provider` (optional), `platform` (optional)

3) Unregister device
- `DELETE /notiva/devices` (20/min)
- Body/Query: `user_uuid` (required) and either `uuid` OR (`provider` + `device_token`)
- Optional: `force=true` to hard delete instead of revoke

## Notifiable Contract
Implement `routeNotificationFor('push')` on your notifiable entity to return tokens/subscriptions:

```
// Examples of supported shapes
return [
  'fcm' => ['fcm-token-1', 'fcm-token-2'],
  'apns' => ['apns-token-1'],
  'webpush' => [
    ['endpoint' => '...', 'keys' => ['p256dh' => '...', 'auth' => '...']],
  ],
];
```

## Notes
- Channels supported: FCM HTTP v1, direct APNs (pushok), and Web Push (minishlink/web-push).
- Graceful fallbacks: if APNs/Web Push libraries are not installed or config is missing, those channels are skipped with logs; others continue.
- Device registry: includes migration for `push_devices` and secure endpoints to register/list/unregister.
- Middleware: endpoints ship with `auth` and `rate_limit` middleware; adjust per your needs.

## FCM HTTP v1
- Notiva uses FCM HTTP v1 exclusively.
- Require `NOTIVA_FCM_CREDENTIALS` and `NOTIVA_FCM_PROJECT`.
- The service account JSON must include `client_email` and `private_key`.
- Tokens are sent individually to `projects/{project}/messages:send` and results aggregated.

## Web Push Setup
- Install library: `composer require minishlink/web-push`
- Configure VAPID keys in env or config:
  - `NOTIVA_WEBPUSH_ENABLED=true`
  - `NOTIVA_VAPID_PUBLIC_KEY=...`
  - `NOTIVA_VAPID_PRIVATE_KEY=...`
  - `NOTIVA_VAPID_SUBJECT=mailto:you@example.com` (or your site origin)
- Notifiable should return subscriptions from `routeNotificationFor('push')`:
```
return [
  'webpush' => [
    [
      'endpoint' => 'https://fcm.googleapis.com/fcm/send/XXX',
      'keys' => [
        'p256dh' => 'BASE64_PUBLIC_KEY',
        'auth' => 'BASE64_AUTH'
      ]
    ]
  ]
];
```
- Supported payload fields for Web Push (client-side Notification options):
  - `title`, `body`, `icon`, `image`, `badge`
  - `data` (object), `tag`
  - `renotify` (bool), `requireInteraction` (bool)
  - `actions` (array of `{action, title, icon}`)
  - Delivery options: `ttl` (seconds), `urgency` (`very-low|low|normal|high`)

## APNs Setup
- Install library: `composer require edamov/pushok`
- Recommended (token-based) configuration:
  - `NOTIVA_APNS_ENABLED=true`
  - `NOTIVA_APNS_P8_PATH=/path/to/AuthKey_XXXX.p8`
  - `NOTIVA_APNS_KEY_ID=XXXX`
  - `NOTIVA_APNS_TEAM_ID=YYYY`
  - `NOTIVA_APNS_BUNDLE_ID=com.example.app`
  - `NOTIVA_APNS_SANDBOX=true` (development) or `false` (production)
- Certificate-based (fallback):
  - `NOTIVA_APNS_ENABLED=true`
  - `NOTIVA_APNS_CERT=/path/to/cert.pem`
  - `NOTIVA_APNS_PASSPHRASE=optional`
  - `NOTIVA_APNS_BUNDLE_ID=com.example.app`
  - `NOTIVA_APNS_SANDBOX=true|false`
- Notifiable should return APNs tokens from `routeNotificationFor('push')`:
```
return [
  'apns' => ['apns-token-1', 'apns-token-2']
];
```
- Supported payload fields for APNs:
  - `title`, `body`, `sound`, `badge`, `category`, `data` (object)
  - `apns_push_type` (e.g., `alert`, `background`)
  - `apns_priority` (`10` immediate, `5` background)
  - `collapse_id` (to coalesce notifications)
- Notes:
  - APNs topic is set from `NOTIVA_APNS_BUNDLE_ID`.
  - `NOTIVA_APNS_SANDBOX` selects api.sandbox.push.apple.com vs api.push.apple.com.

## Usage (PHP)

Direct push via the Notiva channel using ChannelManager:

```php
<?php
use Glueful\Notifications\Services\ChannelManager;
use App\Models\User; // implements Notifiable and routeNotificationFor('push')

// Resolve the push channel
$channels = container()->get(ChannelManager::class);
$push = $channels->getChannel('push');

// Target notifiable
$user = User::findByUuid('u_ABC123');

// Build payload (common + per-channel options as needed)
$payload = [
    'title' => 'Hello',
    'body'  => 'World',
    'data'  => ['foo' => 'bar'],

    // Android (FCM)
    'android_priority' => 'HIGH',
    'click_action'     => 'OPEN_APP',

    // iOS (APNs)
    'apns_push_type' => 'alert',
    'apns_priority'  => 10,

    // Web Push (Browser)
    'actions' => [
        ['action' => 'open', 'title' => 'Open']
    ],
];

// Send
$ok = $push->send($user, $payload);
```

Minimal Notifiable example for push routing:

```php
<?php
use Glueful\Notifications\Contracts\Notifiable;

class User implements Notifiable
{
    public function getNotifiableId(): string { return $this->uuid; }

    public function shouldReceiveNotification(string $type, string $channel): bool { return true; }

    public function getNotificationPreferences(): array { return []; }

    public function routeNotificationFor(string $channel)
    {
        if ($channel !== 'push') return null;
        return [
            'fcm' => ['fcm-token-1'],
            'apns' => ['apns-token-1'],
            // 'webpush' => [[ 'endpoint' => '...', 'keys' => ['p256dh' => '...', 'auth' => '...'] ]]
        ];
    }
}
```

Using NotificationDispatcher (alternative):

```php
<?php
use Glueful\\Notifications\\Services\\NotificationDispatcher;
use Glueful\\Notifications\\Models\\Notification;
use App\\Models\\User; // your Notifiable implementation

$dispatcher = container()->get(NotificationDispatcher::class);

// Build a Notification model; type is arbitrary for your domain
$notification = new Notification([
    'type' => 'system_alert',
    'data' => [
        'title' => 'Hello',
        'body'  => 'World',
        'data'  => ['foo' => 'bar'],
        // optional per-channel fields supported as in the direct example
    ],
]);

$user = User::findByUuid('u_ABC123');

// Restrict to the push channel explicitly
$result = $dispatcher->send($notification, $user, ['push']);
```

Both approaches route through the same Notiva push channel; use the one that best fits your app’s architecture.

## Security Considerations

- Do not log full device tokens or full subscription payloads in production; enable debug logs only in safe environments.
- Treat VAPID and APNs credentials as secrets; restrict file permissions on `.p8`/cert files and avoid committing them.
- Prefer HTTPS endpoints and secure origins for Web Push; set a meaningful VAPID `subject` (mailto or site origin).
- Rotate device tokens when clients signal changes; use the provided `device_id` to help invalidate old tokens.
- Apply rate limits on registration endpoints (already included) to prevent abuse.

## Environment Variables (Quick Reference)

- FCM (HTTP v1)
  - `NOTIVA_FCM_ENABLED=true`
  - `NOTIVA_FCM_CREDENTIALS=/path/to/service-account.json` (or raw JSON)
  - `NOTIVA_FCM_PROJECT=your-gcp-project-id`

- APNs (Token-based, recommended)
  - `NOTIVA_APNS_ENABLED=true`
  - `NOTIVA_APNS_P8_PATH=/path/to/AuthKey_XXXX.p8`
  - `NOTIVA_APNS_KEY_ID=XXXX`
  - `NOTIVA_APNS_TEAM_ID=YYYY`
  - `NOTIVA_APNS_BUNDLE_ID=com.example.app`
  - `NOTIVA_APNS_SANDBOX=true|false`

- APNs (Certificate-based, fallback)
  - `NOTIVA_APNS_ENABLED=true`
  - `NOTIVA_APNS_CERT=/path/to/cert.pem`
  - `NOTIVA_APNS_PASSPHRASE=optional`
  - `NOTIVA_APNS_BUNDLE_ID=com.example.app`
  - `NOTIVA_APNS_SANDBOX=true|false`

- Web Push (VAPID)
  - `NOTIVA_WEBPUSH_ENABLED=true`
  - `NOTIVA_VAPID_PUBLIC_KEY=...`
  - `NOTIVA_VAPID_PRIVATE_KEY=...`
  - `NOTIVA_VAPID_SUBJECT=mailto:you@example.com` (or site origin)

## Payload Fields by Channel
- Common: `title`, `body`, `image`, `badge`, `sound`, `data` (assoc array)
- FCM (Android): `android_priority`, `ttl`, `android_channel_id|channel_id`, `click_action`, `icon`, `color`, `tag`
- APNs (iOS): `apns_push_type` (`alert|background`), `apns_priority` (`10|5`), `category`, `collapse_id`
- Web Push (Browser): `icon`, `badge`, `tag`, `renotify`, `requireInteraction`, `actions`, `ttl`, `urgency`

## Metadata
- Provider: `Glueful\Extensions\Notiva\NotivaServiceProvider`
- Channel: `push`

## Troubleshooting

- FCM HTTP v1
  - "FCM v1 configuration missing": ensure `NOTIVA_FCM_CREDENTIALS` (path or raw JSON) and `NOTIVA_FCM_PROJECT` are set. The JSON must include `client_email` and `private_key`. OpenSSL must be enabled for JWT signing.
  - "OAuth token request failed": check network, credentials validity, and server time (JWT `iat/exp`).

- APNs (pushok)
  - "APNs library not installed": run `composer require edamov/pushok`.
  - "APNs configuration incomplete":
    - Token auth requires `NOTIVA_APNS_P8_PATH`, `NOTIVA_APNS_KEY_ID`, `NOTIVA_APNS_TEAM_ID`, `NOTIVA_APNS_BUNDLE_ID`.
    - Certificate auth requires `NOTIVA_APNS_CERT` (and optional `NOTIVA_APNS_PASSPHRASE`) and `NOTIVA_APNS_BUNDLE_ID`.
  - Topic/capability errors: ensure `NOTIVA_APNS_BUNDLE_ID` matches the app’s bundle identifier used to create credentials.
  - Sandbox vs production: set `NOTIVA_APNS_SANDBOX` to match the environment of your token/cert.

- Web Push (VAPID)
  - "WebPush library not installed": run `composer require minishlink/web-push`.
  - "WebPush VAPID configuration missing": set `NOTIVA_WEBPUSH_ENABLED=true`, `NOTIVA_VAPID_PUBLIC_KEY`, `NOTIVA_VAPID_PRIVATE_KEY`, and `NOTIVA_VAPID_SUBJECT` (`mailto:` or your origin URL).
  - 410 Gone / invalid subscription: client must re-subscribe; call the unregister endpoint to remove the old subscription.
  - No browser notification: ensure your service worker/site JS displays notifications from the JSON payload.

- Device Registry
  - Duplicate token constraint: unique on `(provider, device_token)`. Provide a stable `device_id` so rotation logic can invalidate prior tokens.
  - Missing table / SQL errors: run migrations `php glueful migrate run`.

- Auth and rate limits
  - 401/403: endpoints are protected by `auth` middleware; include a valid bearer token.
  - 429: default `rate_limit` middleware applies; adjust route limits if necessary.

- General
  - Rebuild extension cache after composer or config changes: `php glueful extensions:cache`.
  - Logs are written via `Glueful\Logging\LogManager` (channel `push`); inspect logs for delivery errors.
