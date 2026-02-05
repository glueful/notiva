<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Controllers;

use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Device Controller
 *
 * Handles push notification device registration and management.
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
        $this->injectUserUuid($request);
        return $this->deviceRegistry->register($request);
    }

    /**
     * List registered devices for the authenticated user
     *
     * @route GET /notiva/devices
     */
    public function index(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if ($user && !$request->query->has('user_uuid')) {
            $request->query->set('user_uuid', $user['uuid'] ?? null);
        }
        return $this->deviceRegistry->list($request);
    }

    /**
     * Unregister a push device
     *
     * @route DELETE /notiva/devices
     */
    public function destroy(Request $request): Response
    {
        $this->injectUserUuid($request);
        return $this->deviceRegistry->unregister($request);
    }

    /**
     * Inject user UUID from authenticated user into request data
     */
    private function injectUserUuid(Request $request): void
    {
        $user = $request->attributes->get('user');
        if ($user) {
            $request->request->set('user_uuid', $user['uuid'] ?? null);
        }
    }
}
