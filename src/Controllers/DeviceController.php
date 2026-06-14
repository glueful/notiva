<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Controllers;

use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Device Controller
 *
 * Handles push notification device registration and management. The owner of every
 * operation is the authenticated user (resolved from the request attributes set by
 * the auth middleware) — never client-supplied input, so callers cannot act on
 * another user's devices.
 */
class DeviceController
{
    private DeviceRegistry $deviceRegistry;

    public function __construct(DeviceRegistry $deviceRegistry)
    {
        $this->deviceRegistry = $deviceRegistry;
    }

    /**
     * Register or update a push device.
     */
    #[ApiOperation(
        summary: 'Register Push Device',
        description: 'Registers or updates a push device token/subscription for the authenticated user. '
            . 'Body: `provider` (required; fcm|apns|webpush), `platform` (android|ios|web), '
            . '`device_token` (for FCM/APNs), `subscription` (Web Push subscription JSON for webpush), '
            . '`device_id`, `app_id`, `bundle_id`, `locale`, `timezone`. Requires authentication.',
        tags: ['Notifications'],
    )]
    #[ApiResponse(200, description: 'Device registered')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(Request $request): Response
    {
        $userUuid = $this->resolveUserUuid($request);
        if ($userUuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return $this->deviceRegistry->register($request, $userUuid);
    }

    /**
     * List registered devices for the authenticated user.
     */
    #[ApiOperation(
        summary: 'List Push Devices',
        description: 'Lists registered devices for the authenticated user (filterable by provider/platform). '
            . 'Requires authentication.',
        tags: ['Notifications'],
    )]
    #[ApiResponse(200, description: 'Devices retrieved')]
    #[ApiResponse(401, description: 'Authentication required')]
    public function index(Request $request): Response
    {
        $userUuid = $this->resolveUserUuid($request);
        if ($userUuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return $this->deviceRegistry->list($request, $userUuid);
    }

    /**
     * Unregister a push device.
     */
    #[ApiOperation(
        summary: 'Unregister Push Device',
        description: 'Unregisters (revokes) a device for the authenticated user using uuid or '
            . 'provider+device_token. Body: `uuid` (Device UUID, alternative to provider+device_token), '
            . '`provider` (when using device_token; fcm|apns|webpush), `device_token` (when using provider), '
            . '`force` (if true, hard-delete instead of revoke; default: false). Requires authentication.',
        tags: ['Notifications'],
    )]
    #[ApiResponse(200, description: 'Device unregistered')]
    #[ApiResponse(401, description: 'Authentication required')]
    public function destroy(Request $request): Response
    {
        $userUuid = $this->resolveUserUuid($request);
        if ($userUuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return $this->deviceRegistry->unregister($request, $userUuid);
    }

    /**
     * Resolve the authenticated user's UUID from the request attributes
     * populated by the auth middleware.
     */
    private function resolveUserUuid(Request $request): ?string
    {
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['uuid']) && is_string($user['uuid']) && $user['uuid'] !== '') {
            return $user['uuid'];
        }
        return null;
    }
}
