# Changelog

All notable changes to the Notiva (Push Notifications) extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

- **Device endpoints no longer trust client-supplied `user_uuid` (IDOR).** All three `/notiva/devices` endpoints scoped operations to a `user_uuid` that could be overridden by the client (JSON body won the input merge on POST/DELETE; the query string won on GET). An authenticated user could register their own device token under another user's account (receiving that user's pushes), list another user's devices **including full device tokens**, and revoke or hard-delete another user's registrations. `DeviceController` now resolves the owner exclusively from the authenticated request attributes and passes it to `DeviceRegistry::register()/list()/unregister()` as an explicit argument; `user_uuid` is stripped from client input. **Breaking:** the `DeviceRegistry` method signatures gained a required `string $userUuid` parameter, and a client-sent `user_uuid` is now ignored.
- **Database error messages are no longer returned to clients.** `DeviceRegistry` 500 responses previously embedded `$e->getMessage()` (driver/SQL detail); errors are now logged to the `notiva` channel and the response carries only a generic `db_error` marker.
- **`NotivaProvider::getExtensionInfo()` no longer exposes credentials.** It previously returned the full merged config — APNs passphrase, VAPID private key, and raw FCM service-account JSON. It now returns a sanitized summary (driver enabled flags, default order, feature flags).

### Fixed

- **Per-route rate limits are now actually enforced.** Routes used the `rate_limit:60,60` string form, whose parameters `EnhancedRateLimiterMiddleware` ignores (limits come only from `Route::getRateLimitConfig()`); the routes silently fell back to tier/global defaults. Now registered as `->middleware(['auth', 'rate_limit'])->rateLimit(N, 1)` (60/100/20 requests per minute for register/list/unregister).
- **Web Push delivery failures were reported as success.** `sendWebPush()` set `successAny = true` unconditionally after `sendOneNotification()` — which flushes immediately and returns a `MessageSentReport` — and the subsequent `flush()` loop iterated an already-empty generator (the failure logging was dead code). An expired (410) subscription counted as a delivered push. The report is now inspected: only `isSuccess()` counts, and failures log endpoint, reason, and expiry.
- **Token rotation no longer invalidates a user's other devices.** Registering without a `device_id` marked **all** of the user's other tokens for that provider invalid — a user with two iPhones lost push on the first when the second registered. Rotation now applies only when a `device_id` identifies the physical device, also catches NULL-token rows (which `!=` never matched), and rotation + upsert run inside a transaction so a failed upsert can no longer leave a user with zero active tokens.
- **FCM token cache now skips the RS256 signing.** `getFcmAccessToken()` built and signed the OAuth JWT before consulting the token cache, so the cache only saved the HTTP round trip; the cache check now runs first.

### Changed

- Dropped the unused `vlucas/phpdotenv` hard dependency (the extension's only non-PHP requirement; nothing in `src/` used it).
- phpcs scripts switched from the Squiz standard to PSR-12 (matching the framework); `src/` is PSR-12 clean.
- README updated: `user_uuid` removed from endpoint docs and curl examples; device ownership documented as token-derived.

### Internal

- **`DeviceRegistry` test coverage added** (`tests/Unit/DeviceRegistryTest.php`, SQLite harness): ownership scoping (client `user_uuid` ignored on register/list/unregister, cross-user revocation blocked), upsert behavior, `device_id`-scoped rotation, multi-device preservation, webpush endpoint-hash tokens, and validation errors.

### Planned
- Batch push notification sending
- Push notification templates
- Delivery analytics and reporting
- Silent push support for background updates
- Topic-based subscriptions

## [0.10.0] - 2026-06-06 — Notification Subsystem Refinement (Framework 1.51)

### Added

- **Structured channel results.** `PushChannel` now implements `Glueful\Notifications\Contracts\RichNotificationChannel` and returns a `NotificationResult` from `sendNotification()` — surfacing send latency, per-driver outcome metadata (`drivers_attempted` / `drivers_succeeded`), and stable error codes: `no_targets` (no push routes → non-retryable), `no_eligible_driver` (no enabled driver matched a target → non-retryable, a config/targeting issue), and `all_drivers_failed` (every attempted driver failed → retryable). The framework dispatcher (1.51.0+) records these per channel; the legacy `send(): bool` contract is preserved by delegating to `sendNotification()`. Push is multi-target/multi-driver, so no single provider message id is surfaced.

### Changed

- **Minimum framework requirement raised to `glueful/framework >=1.51.0`** (`require-dev` pinned to `^1.51.0`).
- **Channel/hook registration migrated to the framework's extension helpers.** `NotivaServiceProvider::boot()` now calls `registerNotificationChannel()` / `registerNotificationExtension()` instead of reaching into the container by hand. This is now the **only** wiring path — framework 1.51.0 stopped hardcoding notification providers in its jobs, so an extension that doesn't register from `boot()` won't auto-wire into the shared dispatcher used by async dispatch/retries.

### Fixed

- **APNs delivery was non-functional and is now corrected.** The APNs path targeted a pushok API that does not exist in any released version: it guarded on `class_exists('Pushok\ApnsClient')` (the real client is `Pushok\Client`, so the guard was always false and **APNs never sent**), and used `new Token([…])` / `new Certificate(…)` (private constructors — must use `::create([…])`), `Notification::setPushType/setTopic/setPriority` (nonexistent — push type lives on `Payload`, priority is `setHighPriority()/setLowPriority()`, topic derives from the auth provider), and `new Pushok\Sender(…)->send()` (no such class — it's `Client::addNotifications()->push()`). `sendApns()` is rewritten against the real **pushok `^0.19`** API; FCM and Web Push were unaffected. (Cannot be delivery-verified without live Apple credentials; the payload/notification building is covered by unit tests.)
- **Extension version reporting.** `composerVersion()` read a non-existent top-level `version` key (returning `0.0.0` to the CLI/diagnostics); it now reads the canonical `extra.glueful.version`.
- **Static-analysis cleanups (no behavior change).** Removed a dead `is_string()` branch in FCM token normalization, dropped a redundant `isset()` on the always-present FCM token-cache keys, tightened `buildApnsOptions()` to its real non-null `array` return type, and corrected a stale `@param` on `DeviceRegistry::register()`.

### Internal

- **Test harness + APNs unit tests.** Added `phpunit.xml` and `tests/Unit/PushChannelApnsTest.php` covering the pure APNs payload-building (`buildApnsPayload`), token normalization, and the `RichNotificationChannel` no-targets result.
- **Optional push libraries added to `require-dev`** (`edamov/pushok ^0.19`, `minishlink/web-push ^9.0`) so static analysis and tests exercise the real driver APIs. They remain `suggest`-only at runtime (corrected the unsatisfiable pushok `^1.0` suggestion to `^0.19`). PHPStan (level 5) is now clean across `src`.

### Notes

- The FCM HTTP v1 and Web Push delivery paths, driver ordering, and device registration are unchanged; only the previously-broken APNs path was rewritten.

## [0.9.0] - 2026-06-05 — Framework 1.50 Compatibility

### Changed

- **Dropped the cross-package FK** from `push_devices.user_uuid` → `users(uuid)`. `user_uuid` is now an **indexed logical reference** (the `users` table is owned by `glueful/users`; Phase-5 decoupling disallows cross-package FKs — integrity is enforced at the service layer). It remains nullable and indexed.
- **Migrations register at `MigrationPriority::DEPENDENT`** with source `glueful/notiva` (previously a bare `loadMigrationsFrom()` with no priority/source — the old FK relied on migration ordering that was never guaranteed).
- **Minimum framework raised to `glueful/framework >=1.50.1`** (`require-dev` pinned to `^1.50.1`); previously `>=1.28.0`.

### Notes

- Compatibility/decoupling release — **no change to push delivery or device registration**. `PushChannel` uses the current `NotificationChannel` contract, and device storage is the extension's own `push_devices` table (queried by the now-indexed `user_uuid`). Notiva never referenced the removed `Glueful\Repository\UserRepository`.

## [0.8.4] - 2026-02-24

### Fixed
- **Device registration 500 on PostgreSQL**: `DeviceRegistry::register()` was using the framework's `upsert()` which treated record values (including nulls) as identifiers in the PostgreSQL `ON CONFLICT` clause. Replaced with a database-agnostic find → update/insert flow: lookup by `provider` + `device_token`, then update if found or insert if not.
- **Web Push missing endpoint validation**: If `subscription.endpoint` is absent or empty, the derived device token would be null, causing a DB error downstream. Now returns a validation error (`subscription.endpoint is required`) before hitting the database.

### Notes
- Patch release. No breaking changes.
- The `upsert()` removal makes device registration fully database-agnostic (MySQL, PostgreSQL, SQLite).
- The `orderBy` scalar fix from 0.8.3 is preserved.

## [0.8.3] - 2026-02-24

### Fixed
- **Device list 500 on PostgreSQL**: `DeviceRegistry::getDevices()` was using the associative-array `orderBy()` form (`['last_seen_at' => 'DESC']`), which hit a fragile code path in the Glueful PostgreSQL query builder where a null identifier reached `PostgreSQLDriver::wrapIdentifier()`. Changed to the scalar form `orderBy('last_seen_at', 'DESC')`.

### Notes
- Patch release. No breaking changes.
- Only affects PostgreSQL deployments — MySQL/SQLite were unaffected.

## [0.8.2] - 2026-02-09

### Fixed
- **Controller DI Registration**: `DeviceController` was not registered in `NotivaServiceProvider::services()`, causing `Service not found` errors when the router resolved the controller from the container. Controller is now explicitly registered with its dependencies.

### Notes
- Patch release. No breaking changes.

## [0.8.1] - 2026-02-06

### Changed
- **Version Management**: Version is now read from `composer.json` at runtime via `NotivaServiceProvider::composerVersion()`.
  - `getVersion()` now returns `self::composerVersion()` instead of a hardcoded string.
  - `registerMeta()` in `boot()` now uses `self::composerVersion()`.
  - `NotivaProvider::getExtensionInfo()` now references `NotivaServiceProvider::composerVersion()`.
  - Future releases only require updating `composer.json` and `CHANGELOG.md`.

### Fixed
- **Version Mismatch**: `getVersion()` was returning `0.7.0` while `composer.json` and `registerMeta()` specified `0.8.0`. All version references now read from `composer.json` as single source of truth.

### Notes
- No breaking changes. Internal refactor only.

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
