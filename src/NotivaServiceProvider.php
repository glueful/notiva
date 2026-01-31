<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Notifications\Services\ChannelManager;

class NotivaServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public function getName(): string
    {
        return 'Notiva';
    }

    public function getVersion(): string
    {
        return '0.7.0';
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
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('notiva', require __DIR__ . '/../config/notiva.php');
    }

    public function boot(ApplicationContext $context): void
    {
        if ($this->app->has(ChannelManager::class)) {
            $provider = $this->app->get(NotivaProvider::class);
            if (method_exists($provider, 'initialize')) {
                $provider->initialize();
            }

            $channel = $this->app->get(PushChannel::class);
            $this->app->get(ChannelManager::class)->registerChannel($channel);

            if ($this->app->has(\Glueful\Notifications\Services\NotificationDispatcher::class)) {
                $dispatcher = $this->app->get(\Glueful\Notifications\Services\NotificationDispatcher::class);
                if (method_exists($dispatcher, 'registerExtension')) {
                    $dispatcher->registerExtension($provider);
                }
            }
        }

        // load migrations and routes
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        // Register extension metadata for CLI and diagnostics
        try {
            $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
                'slug' => 'notiva',
                'name' => 'Notiva',
                'version' => '0.7.0',
                'description' => 'Push notifications for Glueful (FCM, APNs, Web Push)',
            ]);
        } catch (\Throwable $e) {
            error_log('[Notiva] Failed to register extension metadata: ' . $e->getMessage());
        }
    }
}
