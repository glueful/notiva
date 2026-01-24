<?php

use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/notiva'], function (Router $router) {
/**
 * @route POST /notiva/devices
 * @summary Register Push Device
 * @description Registers or updates a push device token/subscription for the authenticated user.
 * @tag Notifications
 * @requestBody
 *   provider:string="Provider: fcm|apns|webpush"
 *   platform:string="Device platform: android|ios|web"
 *   device_token:string="Device token (for FCM/APNs)"
 *   subscription:object="Web Push subscription JSON (for webpush)"
 *   device_id:string="Client device identifier"
 *   app_id:string="Application identifier"
 *   bundle_id:string="iOS bundle identifier"
 *   locale:string="Locale (e.g., en-US)"
 *   timezone:string="Timezone (e.g., America/Los_Angeles)"
 * {required=provider}
 * @response 200 application/json "Device registered"
 * @response 422 "Validation failed"
 */
$router->post('/devices', function (Request $request) {
    // Inject user UUID from authenticated user into request data
    $user = $request->attributes->get('user');
    if ($user) {
        $request->request->set('user_uuid', $user['uuid'] ?? null);
    }
    $service = new DeviceRegistry();
    return $service->register($request);
})->middleware(['auth', 'rate_limit:60,60']);

/**
 * @route GET /notiva/devices
 * @summary List Push Devices
 * @description Lists registered devices for the authenticated user (filterable by provider/platform).
 * @tag Notifications
 * @response 200 application/json "Devices retrieved"
 */
$router->get('/devices', function (Request $request) {
    // Get user UUID from authenticated user if not provided
    $user = $request->attributes->get('user');
    if ($user && !$request->query->has('user_uuid')) {
        $request->query->set('user_uuid', $user['uuid'] ?? null);
    }
    $service = new DeviceRegistry();
    return $service->list($request);
})->middleware(['auth', 'rate_limit:100,60']);

/**
 * @route DELETE /notiva/devices
 * @summary Unregister Push Device
 * @description Unregisters (revokes) a device for the authenticated user using uuid or provider+device_token.
 * @tag Notifications
 * @requestBody
 *   uuid:string="Device UUID (alternative to provider+device_token)"
 *   provider:string="Provider when using device_token (fcm|apns|webpush)"
 *   device_token:string="Device token when using provider"
 *   force:boolean="If true, hard-delete instead of revoke (default: false)"
 * @response 200 application/json "Device unregistered"
 */
$router->delete('/devices', function (Request $request) {
    // Inject user UUID from authenticated user into request data
    $user = $request->attributes->get('user');
    if ($user) {
        $request->request->set('user_uuid', $user['uuid'] ?? null);
    }
    $service = new DeviceRegistry();
    return $service->unregister($request);
})->middleware(['auth', 'rate_limit:20,60']);

});
