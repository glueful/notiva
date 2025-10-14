<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Services;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

class DeviceRegistry
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? new Connection();
    }

    /**
     * Register or update a device token/subscription and manage rotation.
     *
     * @param array<string, mixed> $input
     * @return Response
     */
    public function register(Request $request): Response
    {
        $content = $request->getContent();
        $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $data = array_merge($request->query->all(), $request->request->all(), $data);

        $provider = strtolower((string)($data['provider'] ?? ''));
        $platform = $data['platform'] ?? null;
        $deviceToken = $data['device_token'] ?? null;
        $subscription = $data['subscription'] ?? null;
        $deviceId = $data['device_id'] ?? null;
        $appId = $data['app_id'] ?? null;
        $bundleId = $data['bundle_id'] ?? null;
        $locale = $data['locale'] ?? null;
        $timezone = $data['timezone'] ?? null;
        $userUuid = isset($data['user_uuid']) && is_string($data['user_uuid']) ? $data['user_uuid'] : null;

        // Validate required fields
        if ($userUuid === null || $userUuid === '') {
            return Response::validation(['user_uuid' => 'User UUID is required']);
        }

        
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

        // Rotation: mark older tokens invalid for same user+provider+device_id (or user+provider when device_id missing)
        try {
            if ($userUuid !== null) {
                $qb = $this->db->table('push_devices')
                    ->where('provider', '=', $provider)
                    ->where('user_uuid', '=', $userUuid);
                if (!empty($deviceId)) {
                    $qb = $qb->where('device_id', '=', (string)$deviceId);
                }
                $qb->where('device_token', '!=', (string)$deviceToken)
                    ->update([
                        'status' => 'invalid',
                        'invalidated_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            // Upsert current device
            $affected = $this->db->table('push_devices')->upsert(
                $record,
                [
                    'user_uuid', 'notifiable_type', 'notifiable_id', 'platform', 'subscription_json',
                    'device_id', 'app_id', 'bundle_id', 'locale', 'timezone', 'status', 'last_seen_at', 'updated_at'
                ]
            );

            return Response::success([
                'affected' => $affected,
                'uuid' => $uuid,
                'provider' => $provider,
                'platform' => $platform,
            ], 'Device registered');
        } catch (\Throwable $e) {
            return Response::error('Database error', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List registered devices for a user (optionally filter by provider/platform).
     */
    public function list(Request $request): Response
    {
        try {
            $query = $request->query->all();
            $userUuid = isset($query['user_uuid']) && is_string($query['user_uuid']) ? $query['user_uuid'] : null;
            if ($userUuid === null || $userUuid === '') {
                return Response::validation(['user_uuid' => 'User UUID is required']);
            }

            $provider = isset($query['provider']) && is_string($query['provider']) ? strtolower($query['provider']) : null;
            $platform = isset($query['platform']) && is_string($query['platform']) ? strtolower($query['platform']) : null;

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
                ->orderBy(['last_seen_at' => 'DESC'])
                ->get();

            return Response::success(['devices' => $devices], 'Devices retrieved');
        } catch (\Throwable $e) {
            return Response::error('Failed to list devices', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Unregister (revoke) a device for a user. Accepts either device uuid or provider+device_token.
     */
    public function unregister(Request $request): Response
    {
        try {
            // Accept JSON, form, or query
            $content = $request->getContent();
            $data = is_string($content) && $content !== '' ? json_decode($content, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
            $data = array_merge($request->query->all(), $request->request->all(), $data);

            $userUuid = isset($data['user_uuid']) && is_string($data['user_uuid']) ? $data['user_uuid'] : null;
            if ($userUuid === null || $userUuid === '') {
                return Response::validation(['user_uuid' => 'User UUID is required']);
            }

            $uuid = isset($data['uuid']) && is_string($data['uuid']) ? $data['uuid'] : null;
            $provider = isset($data['provider']) && is_string($data['provider']) ? strtolower($data['provider']) : null;
            $deviceToken = isset($data['device_token']) && is_string($data['device_token']) ? $data['device_token'] : null;
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
            return Response::error('Failed to unregister device', Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'db_error',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
