<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Notifications\Services\ChannelManager;

class NotivaServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    public function getName(): string
    {
        return 'Notiva';
    }

    public function getVersion(): string
    {
        return '0.1.0';
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
            ],
            NotivaProvider::class => [
                'class' => NotivaProvider::class,
                'shared' => true,
            ],
        ];
    }

    public function register(): void
    {
        $this->mergeConfig('notiva', require __DIR__ . '/../config/notiva.php');

        // Register database migrations for this extension
        $this->loadMigrationsFrom(__DIR__ . '/../migrations');
    }

    public function boot(): void
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
    }

    public function routes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
    }

    public function getDependencies(): array
    {
        return [];
    }
}
