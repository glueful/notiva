# Notiva — Push Notifications for Glueful

**Notiva** is a Glueful extension that brings seamless push notification delivery to your apps.
Send instant alerts to mobile or web devices via FCM, APNs, and browser push with simple APIs
and configurable message templates.

### Features
- Unified API for push delivery
- Mobile support
- Token registration and management
- Configurable message payloads
- Delivery tracking and analytics (optional)

### Getting Started
- Require the extension in your Glueful app and register the provider `Glueful\Extensions\Notiva\NotivaServiceProvider`.
- Configure `config/notiva.php` (published in the extension) or set the relevant env vars:
  - `NOTIVA_FCM_ENABLED`
  - HTTP v1 only: `NOTIVA_FCM_CREDENTIALS` (service account JSON or path) and `NOTIVA_FCM_PROJECT`
  - `NOTIVA_APNS_ENABLED`, `NOTIVA_APNS_P8_PATH`, `NOTIVA_APNS_KEY_ID`, `NOTIVA_APNS_TEAM_ID`, `NOTIVA_APNS_BUNDLE_ID`
  - `NOTIVA_WEBPUSH_ENABLED`, `NOTIVA_VAPID_PUBLIC_KEY`, `NOTIVA_VAPID_PRIVATE_KEY`, `NOTIVA_VAPID_SUBJECT`

### Notifiable Contract
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

### Notes
- This is an initial scaffold. Integration libraries you might consider for advanced features:
  - APNs: `edamov/pushok`
  - Web Push: `minishlink/web-push`

### FCM HTTP v1
- Notiva uses FCM HTTP v1 exclusively.
- Require `NOTIVA_FCM_CREDENTIALS` and `NOTIVA_FCM_PROJECT`.
- The service account JSON must include `client_email` and `private_key`.
- Tokens are sent individually to `projects/{project}/messages:send` and results aggregated.

### Metadata
- Provider: `Glueful\Extensions\Notiva\NotivaServiceProvider`
- Channel: `push`
