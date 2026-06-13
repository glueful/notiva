<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Controllers;

use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use Glueful\Http\Response;
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
     * Register or update a push device
     *
     * @route POST /notiva/devices
     */
    public function store(Request $request): Response
    {
        $userUuid = $this->resolveUserUuid($request);
        if ($userUuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return $this->deviceRegistry->register($request, $userUuid);
    }

    /**
     * List registered devices for the authenticated user
     *
     * @route GET /notiva/devices
     */
    public function index(Request $request): Response
    {
        $userUuid = $this->resolveUserUuid($request);
        if ($userUuid === null) {
            return Response::unauthorized('Authentication required');
        }
        return $this->deviceRegistry->list($request, $userUuid);
    }

    /**
     * Unregister a push device
     *
     * @route DELETE /notiva/devices
     */
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
