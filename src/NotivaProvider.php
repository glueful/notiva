<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationExtension;
use Glueful\Notifications\Services\ChannelManager;

/**
 * Notiva Provider — NotificationExtension implementation
 */
class NotivaProvider implements NotificationExtension
{
    /** @var array<string, mixed> */
    private array $config;
    private bool $initialized = false;
    private ?PushChannel $channel = null;
    private LogManager $logger;
    private ApplicationContext $context;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(ApplicationContext $context, array $config = [])
    {
        $this->context = $context;
        $extensionConfig = config($this->context, 'notiva') ?? [];
        $this->config = array_merge($extensionConfig, $config);
        $this->logger = new LogManager('notiva');
    }

    public function getExtensionName(): string
    {
        return 'notiva';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function initialize(array $config = []): bool
    {
        if ($config !== []) {
            $this->config = array_merge($this->config, $config);
        }

        try {
            $formatter = new PushFormatter();
            $this->channel = new PushChannel($this->context, $this->config, $formatter);

            if (!$this->channel->isAvailable()) {
                return false;
            }

            $this->initialized = true;
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize Notiva', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public function getSupportedNotificationTypes(): array
    {
        // By default support all types; apps can narrow if needed
        return ['*'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function beforeSend(array $data, Notifiable $notifiable, string $channel): array
    {
        if ($channel !== 'push') {
            return $data;
        }

        if (!isset($data['title']) && isset($data['subject'])) {
            $data['title'] = (string) $data['subject'];
        }

        if (($this->config['features']['debug'] ?? false) === true) {
            $this->logger->debug('Preparing push notification', [
                'recipient' => $notifiable->getNotifiableId(),
                'title' => $data['title'] ?? null,
            ]);
        }

        return $data;
    }

    public function afterSend(array $data, Notifiable $notifiable, string $channel, bool $success): void
    {
        if ($channel !== 'push') {
            return;
        }

        if (($this->config['features']['track_delivery'] ?? false) !== true) {
            return;
        }

        if ($success) {
            $this->logger->info('Push notification sent', [
                'recipient' => $notifiable->getNotifiableId(),
                'title' => $data['title'] ?? null,
            ]);
        } else {
            $this->logger->warning('Push notification failed', [
                'recipient' => $notifiable->getNotifiableId(),
                'title' => $data['title'] ?? null,
            ]);
        }
    }

    public function register(ChannelManager $channelManager): void
    {
        if (!$this->initialized) {
            $this->initialize($this->config);
        }
        if ($this->channel !== null) {
            $channelManager->registerChannel($this->channel);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtensionInfo(): array
    {
        return [
            'name' => 'Notiva Push Notifications',
            'version' => NotivaServiceProvider::composerVersion(),
            'channels' => ['push'],
            'config' => $this->config,
        ];
    }
}

