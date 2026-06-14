<?php

declare(strict_types=1);

use Glueful\Extensions\Notiva\Controllers\DeviceController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/notiva'], function (Router $router) {
    // Push device management
    $router->post('/devices', [DeviceController::class, 'store'])
        ->middleware(['auth', 'rate_limit'])
        ->rateLimit(60, 1); // 60 requests per minute

    $router->get('/devices', [DeviceController::class, 'index'])
        ->middleware(['auth', 'rate_limit'])
        ->rateLimit(100, 1); // 100 requests per minute

    $router->delete('/devices', [DeviceController::class, 'destroy'])
        ->middleware(['auth', 'rate_limit'])
        ->rateLimit(20, 1); // 20 requests per minute
});
