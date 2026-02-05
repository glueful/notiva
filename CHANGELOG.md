# Changelog

All notable changes to the Notiva (Push Notifications) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Planned
- Batch push notification sending
- Push notification templates
- Delivery analytics and reporting
- Silent push support for background updates
- Topic-based subscriptions

## [0.8.0] - 2026-02-05

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.28.0
  - Compatible with route caching infrastructure (Bellatrix release)
  - Routes converted from closures to `[Controller::class, 'method']` syntax for cache compatibility
- **Route Refactoring**: All device management routes now use controller syntax
  - `POST /notiva/devices` → `DeviceController::store`
  - `GET /notiva/devices` → `DeviceController::index`
  - `DELETE /notiva/devices` → `DeviceController::destroy`
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.28.0`

### Added
- **DeviceController**: New controller for push device management
  - `store` - Register or update a push device token/subscription
  - `index` - List registered devices for authenticated user
  - `destroy` - Unregister a device by UUID or provider+token
  - Helper method `injectUserUuid` for automatic user context

### Notes
- This release enables route caching for improved performance
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [0.7.0] - 2026-01-31

### Changed
- **Framework Compatibility**: Updated minimum framework requirement to Glueful 1.22.0
  - Compatible with the new `ApplicationContext` dependency injection pattern
  - No code changes required in extension - framework handles context propagation
- **composer.json**: Updated `extra.glueful.requires.glueful` to `>=1.22.0`

### Notes
- This release ensures compatibility with Glueful Framework 1.22.0's context-based dependency injection
- All existing functionality remains unchanged
- Run `composer update` after upgrading

## [0.6.2] - 2026-01-24

### Changed
- **Device Routes**: User UUID now automatically injected from authenticated user.
  - `POST /notiva/devices` - No longer requires `user_uuid` in request body.
  - `GET /notiva/devices` - Automatically filters by authenticated user if `user_uuid` not provided.
  - `DELETE /notiva/devices` - No longer requires `user_uuid` in request body.
- **API Documentation**: Updated route descriptions to clarify "authenticated user" context.

### Security
- Device endpoints now enforce user ownership through authentication.
- Users can only register, list, and unregister their own devices.
- Prevents potential IDOR vulnerabilities in device management.

### Notes
- No breaking changes for clients already passing `user_uuid`.
- Compatible with Glueful Framework 1.19.x.

## [0.6.1] - 2026-01-17

### Fixed
- Fixed PSR-4 autoload namespace in composer.json.
- Corrected provider class path in extension metadata.

## [0.6.0] - 2026-01-17

### Breaking Changes
- **PHP 8.3 Required**: Minimum PHP version raised from 8.2 to 8.3.
- **Glueful 1.9.0 Required**: Minimum framework version raised to 1.9.0.

### Changed
- Updated `composer.json` PHP requirement to `^8.3`.
- Updated `extra.glueful.requires.glueful` to `>=1.9.0`.

### Notes
- Ensure your environment runs PHP 8.3 or higher before upgrading.
- Run `composer update` after upgrading.

## [0.5.0] - 2026-01-15

### Changed
- Registered Notiva extension metadata in composer.json.
- Updated extension version and requirements.

## [0.4.0] - 2025-12-20

### Changed
- Updated homepage URL to GitHub repository.
- Updated glueful dependency configuration.

## [0.3.0] - 2025-11-15

### Changed
- Updated extension version and framework requirements.
- Improved compatibility with Glueful's modern extension system.

## [0.2.0] - 2025-10-01

### Added
- **APNs Support**: Direct Apple Push Notification service integration.
  - Token-based authentication with `.p8` key files.
  - Certificate-based authentication support.
  - Environment switching (production/sandbox).
- **Web Push Support**: VAPID-based Web Push notifications.
  - Browser push notification support.
  - Subscription endpoint management.
  - VAPID key pair configuration.

### Enhanced
- **PushChannel**: Unified delivery across all providers.
  - Automatic provider selection based on device type.
  - Consistent response format across providers.
  - Error handling and logging improvements.

## [0.1.0] - 2025-09-01

### Added
- **Initial Release**: Push notification extension scaffold.
- **FCM Support**: Firebase Cloud Messaging HTTP v1 API integration.
  - Android push notifications with custom options.
  - Service account authentication.
  - Message priority and TTL configuration.
- **Device Registry**: Device token management system.
  - `POST /notiva/devices` - Register device tokens.
  - `GET /notiva/devices` - List registered devices.
  - `DELETE /notiva/devices` - Unregister devices.
- **Database Migration**: `push_devices` table for device storage.
  - Multi-provider support (fcm, apns, webpush).
  - Platform tracking (android, ios, web).
  - Soft-delete support for device revocation.
- **Configuration**: Environment variable configuration.
  - FCM project ID and service account path.
  - APNs team ID, key ID, and bundle ID.
  - Web Push VAPID keys.

### Infrastructure
- Modern extension architecture with NotivaServiceProvider.
- PSR-4 autoloading under `Glueful\Extensions\Notiva`.
- Composer-based discovery and installation.

---

## Release Notes

### Version 0.6.2 Highlights

This patch release improves security and developer experience for device management:

- **Automatic User Context**: Device endpoints now automatically use the authenticated user's UUID, eliminating the need to pass `user_uuid` in requests.
- **Enhanced Security**: Prevents potential IDOR vulnerabilities by enforcing user ownership through authentication.
- **Simplified API**: Cleaner request payloads without redundant user identification.

### Push Provider Overview

| Provider | Platform | Authentication | Package Required |
|----------|----------|----------------|------------------|
| FCM | Android, Web | Service Account JSON | Built-in |
| APNs | iOS, macOS | Token (.p8) or Certificate | `edamov/pushok` |
| Web Push | Browsers | VAPID Keys | `minishlink/web-push` |

### Configuration Example

```env
# FCM Configuration
FCM_PROJECT_ID=your-project-id
FCM_CREDENTIALS_PATH=/path/to/service-account.json

# APNs Configuration
APNS_TEAM_ID=XXXXXXXXXX
APNS_KEY_ID=XXXXXXXXXX
APNS_KEY_PATH=/path/to/AuthKey.p8
APNS_BUNDLE_ID=com.yourapp.bundle
APNS_ENVIRONMENT=production

# Web Push Configuration
VAPID_SUBJECT=mailto:admin@example.com
VAPID_PUBLIC_KEY=your-public-key
VAPID_PRIVATE_KEY=your-private-key
```

### API Usage

```bash
# Register device (user_uuid now auto-injected from auth)
curl -X POST /notiva/devices \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"provider": "fcm", "platform": "android", "device_token": "..."}'

# List devices (auto-filtered by authenticated user)
curl /notiva/devices \
  -H "Authorization: Bearer $TOKEN"

# Unregister device
curl -X DELETE /notiva/devices \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"provider": "fcm", "device_token": "..."}'
```

---

**Full Changelog**: https://github.com/glueful/notiva/commits/main
