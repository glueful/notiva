<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Notiva\Controllers\DeviceController;
use Glueful\Extensions\Notiva\Services\DeviceRegistry;

class NotivaServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    private static ?string $cachedVersion = null;

    /**
     * Read the extension version from composer.json (cached)
     */
    public static function composerVersion(): string
    {
        if (self::$cachedVersion === null) {
            $path = __DIR__ . '/../composer.json';
            $composer = json_decode(file_get_contents($path), true);
            // Canonical version lives under extra.glueful.version (this is a library package
            // with no top-level "version" key); fall back to a top-level key then a sentinel.
            self::$cachedVersion = $composer['extra']['glueful']['version']
                ?? ($composer['version'] ?? '0.0.0');
        }

        return self::$cachedVersion;
    }

    public function getName(): string
    {
        return 'Notiva';
    }

    public function getVersion(): string
    {
        return self::composerVersion();
    }

    public function getDescription(): string
    {
        return 'Push notifications for Glueful (FCM, APNs, Web Push)';
    }

    public static function services(): array
    {
        return [
            PushFormatter::class => [
                'class' => PushFormatter::class,
                'shared' => true,
            ],
            PushChannel::class => [
                'class' => PushChannel::class,
                'shared' => true,
                'autowire' => true,
            ],
            NotivaProvider::class => [
                'class' => NotivaProvider::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeviceRegistry::class => [
                'class' => DeviceRegistry::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeviceController::class => [
                'class' => DeviceController::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('notiva', require __DIR__ . '/../config/notiva.php');
    }

    public function boot(ApplicationContext $context): void
    {
        // Register the push channel and its before/after-send hooks through the framework's
        // extension helpers (1.51.0+). These resolve the shared container ChannelManager /
        // NotificationDispatcher and no-op if the notification subsystem isn't present — this is
        // now the only wiring path (the framework no longer hardcodes notification providers).
        $this->registerNotificationChannel($this->app->get(PushChannel::class));
        $this->registerNotificationExtension($this->app->get(NotivaProvider::class));

        // load migrations and routes. push_devices holds a (FK-less) logical reference to
        // users.uuid — owned by glueful/users at IDENTITY — so notiva migrates at DEPENDENT
        // (after identity + app) and records its source as glueful/notiva.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::DEPENDENT, 'glueful/notiva');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // Register extension metadata for CLI and diagnostics
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'notiva',
                'name' => 'Notiva',
                'version' => self::composerVersion(),
                'description' => 'Push notifications for Glueful (FCM, APNs, Web Push)',
            ]);
        } catch (\Throwable $e) {
            error_log('[Notiva] Failed to register extension metadata: ' . $e->getMessage());
        }
    }
}
