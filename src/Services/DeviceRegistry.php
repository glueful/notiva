<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Services;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Logging\LogManager;
use Symfony\Component\HttpFoundation\Request;

class DeviceRegistry
{
    private Connection $db;
    private LogManager $logger;

    public function __construct(?Connection $db = null, ?LogManager $logger = null)
    {
        $this->db = $db ?? new Connection();
        $this->logger = $logger ?? new LogManager('notiva');
    }

    /**
     * Register or update a device token/subscription and manage rotation.
     *
     * The owning user is the authenticated user passed by the controller —
     * client-supplied user identifiers are ignored.
     *
     * @param Request $request
     * @param string $userUuid Authenticated user's UUID (never client input)
     * @return Response
     */
    public function register(Request $request, string $userUuid): Response
    {
        $data = $this->requestData($request);

        $provider = strtolower((string)($data['provider'] ?? ''));
        $platform = $data['platform'] ?? null;
        $deviceToken = $data['device_token'] ?? null;
        $subscription = $data['subscription'] ?? null;
        $deviceId = $data['device_id'] ?? null;
        $appId = $data['app_id'] ?? null;
        $bundleId = $data['bundle_id'] ?? null;
        $locale = $data['locale'] ?? null;
        $timezone = $data['timezone'] ?? null;

        if (!in_array($provider, ['fcm', 'apns', 'webpush'], true)) {
            return Response::error('Invalid provider', Response::HTTP_BAD_REQUEST, ['provider' => $provider]);
        }

        // Normalize token for webpush using endpoint hash
        if ($provider === 'webpush' && is_array($subscription)) {
            $endpoint = (string)($subscription['endpoint'] ?? '');
            if ($endpoint !== '') {
                $deviceToken = 'wp_' . substr(hash('sha256', $endpoint), 0, 64);
            }
        }

        if (($deviceToken === null || $deviceToken === '') && $provider !== 'webpush') {
            return Response::validation(['device_token' => 'Device token is required']);
        }
        if ($provider === 'webpush' && ($deviceToken === null || $deviceToken === '')) {
            return Response::validation(['subscription.endpoint' => 'Web Push subscription endpoint is required']);
        }

        $now = date('Y-m-d H:i:s');
        $uuid = Utils::generateNanoID(12);

        $record = [
            'uuid' => $uuid,
            'user_uuid' => $userUuid,
            'notifiable_type' => $data['notifiable_type'] ?? null,
            'notifiable_id' => $data['notifiable_id'] ?? null,
            'provider' => $provider,
            'platform' => $platform,
            'device_token' => $deviceToken,
            'subscription_json' => is_array($subscription) ? json_encode($subscription) : null,
            'device_id' => $deviceId,
            'app_id' => $appId,
            'bundle_id' => $bundleId,
            'locale' => $locale,
            'timezone' => $timezone,
            'status' => 'active',
            'registered_at' => $now,
            'last_seen_at' => $now,
            'updated_at' => $now,
            'created_at' => $now,
        ];

        try {
            [$affected, $uuid] = $this->db->transaction(
                function () use ($data, $record, $provider, $userUuid, $deviceId, $deviceToken, $now, $uuid) {
                    // Rotation: a device_id identifies a physical device, so a new token for the
                    // same user+provider+device_id supersedes the old one. Without a device_id we
                    // cannot tell devices apart, so we leave other registrations alone (a user may
                    // legitimately hold several devices on the same provider).
                    // Two updates because UPDATE does not support OR-grouped conditions:
                    // one for differing tokens, one for NULL tokens (which '!=' never matches).
                    if (!empty($deviceId)) {
                        $invalidate = [
                            'status' => 'invalid',
                            'invalidated_at' => $now,
                            'updated_at' => $now,
                        ];
                        $rotation = fn() => $this->db->table('push_devices')
                            ->where('provider', '=', $provider)
                            ->where('user_uuid', '=', $userUuid)
                            ->where('device_id', '=', (string)$deviceId);
                        $rotation()->where('device_token', '!=', (string)$deviceToken)->update($invalidate);
                        $rotation()->whereNull('device_token')->update($invalidate);
                    }

                    // Database-agnostic "upsert": update existing row by unique provider+device_token,
                    // else insert. Avoids driver-specific ON CONFLICT / ON DUPLICATE KEY behavior.
                    $existing = $this->db->table('push_devices')
                        ->where('provider', '=', $provider)
                        ->where('device_token', '=', (string) $deviceToken)
                        ->first();

                    if (is_array($existing) && isset($existing['id'])) {
                        $uuid = isset($existing['uuid']) && is_string($existing['uuid']) && $existing['uuid'] !== ''
                            ? $existing['uuid']
                            : $uuid;

                        $affected = $this->db->table('push_devices')
                            ->where('id', '=', $existing['id'])
                            ->update([
                                'user_uuid' => $userUuid,
                                'notifiable_type' => $data['notifiable_type'] ?? null,
                                'notifiable_id' => $data['notifiable_id'] ?? null,
                                'platform' => $record['platform'],
                                'subscription_json' => $record['subscription_json'],
                                'device_id' => $record['device_id'],
                                'app_id' => $record['app_id'],
                                'bundle_id' => $record['bundle_id'],
                                'locale' => $record['locale'],
                                'timezone' => $record['timezone'],
                                'status' => 'active',
                                'last_seen_at' => $now,
                                'updated_at' => $now,
                            ]);
                    } else {
                        $affected = $this->db->table('push_devices')->insert($record);
                    }

                    return [$affected, $uuid];
                }
            );

            return Response::success([
                'affected' => $affected,
                'uuid' => $uuid,
                'provider' => $provider,
                'platform' => $platform,
            ], 'Device registered');
        } catch (\Throwable $e) {
            $this->logger->error('Device registration failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return Response::error('Failed to register device', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
            ]);
        }
    }

    /**
     * List registered devices for the authenticated user
     * (optionally filter by provider/platform).
     */
    public function list(Request $request, string $userUuid): Response
    {
        try {
            $query = $request->query->all();
            $provider = isset($query['provider']) && is_string($query['provider'])
                ? strtolower($query['provider'])
                : null;
            $platform = isset($query['platform']) && is_string($query['platform'])
                ? strtolower($query['platform'])
                : null;

            $qb = $this->db->table('push_devices')->where('user_uuid', '=', $userUuid);
            if ($provider) {
                $qb = $qb->where('provider', '=', $provider);
            }
            if ($platform) {
                $qb = $qb->where('platform', '=', $platform);
            }

            $devices = $qb
                ->select([
                    'uuid', 'provider', 'platform', 'device_id', 'device_token', 'status',
                    'registered_at', 'last_seen_at', 'invalidated_at', 'app_id', 'bundle_id', 'locale', 'timezone'
                ])
                ->orderBy('last_seen_at', 'DESC')
                ->get();

            return Response::success(['devices' => $devices], 'Devices retrieved');
        } catch (\Throwable $e) {
            $this->logger->error('Device listing failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('Failed to list devices', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
            ]);
        }
    }

    /**
     * Unregister (revoke) a device for the authenticated user.
     * Accepts either device uuid or provider+device_token.
     */
    public function unregister(Request $request, string $userUuid): Response
    {
        try {
            $data = $this->requestData($request);

            $uuid = isset($data['uuid']) && is_string($data['uuid']) ? $data['uuid'] : null;
            $provider = isset($data['provider']) && is_string($data['provider'])
                ? strtolower($data['provider'])
                : null;
            $deviceToken = isset($data['device_token']) && is_string($data['device_token'])
                ? $data['device_token']
                : null;
            $force = isset($data['force']) ? filter_var($data['force'], FILTER_VALIDATE_BOOLEAN) : false;

            if ($uuid === null && ($provider === null || $deviceToken === null)) {
                return Response::validation([
                    'uuid|provider+device_token' => 'Provide device uuid or provider+device_token'
                ]);
            }

            $now = date('Y-m-d H:i:s');
            $qb = $this->db->table('push_devices')->where('user_uuid', '=', $userUuid);
            if ($uuid !== null) {
                $qb = $qb->where('uuid', '=', $uuid);
            } else {
                $qb = $qb->where('provider', '=', (string) $provider)
                         ->where('device_token', '=', (string) $deviceToken);
            }

            if ($force === true) {
                $affected = $qb->delete();
            } else {
                $affected = $qb->update([
                    'status' => 'revoked',
                    'invalidated_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return Response::success([
                'affected' => $affected,
                'action' => $force ? 'deleted' : 'revoked'
            ], 'Device unregistered');
        } catch (\Throwable $e) {
            $this->logger->error('Device unregistration failed', [
                'error' => $e->getMessage(),
            ]);
            return Response::error('Failed to unregister device', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
            ]);
        }
    }

    /**
     * Merge JSON body, form, and query input. Identity fields are stripped —
     * ownership always comes from the authenticated user.
     *
     * @return array<string, mixed>
     */
    private function requestData(Request $request): array
    {
        $content = $request->getContent();
        $json = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($json)) {
            $json = [];
        }
        $data = array_merge($request->query->all(), $request->request->all(), $json);
        unset($data['user_uuid']);
        return $data;
    }
}
