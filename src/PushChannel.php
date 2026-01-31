<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;

/**
 * Push Notification Channel (FCM, APNs, Web Push)
 */
class PushChannel implements NotificationChannel
{
    /** @var array<string, mixed> */
    private array $config;
    private PushFormatter $formatter;
    private LogManager $logger;
    private ApplicationContext $context;
    /**
     * Simple in-memory cache for FCM access tokens keyed by client email + scope.
     * @var array<string, array{token: string, exp: int}>
     */
    private static array $fcmTokenCache = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(ApplicationContext $context, array $config = [], ?PushFormatter $formatter = null)
    {
        $this->context = $context;
        $this->config = $config === [] ? (config($this->context, 'notiva') ?? []) : $config;
        $this->formatter = $formatter ?? new PushFormatter();
        $this->logger = new LogManager('push');
    }

    public function getChannelName(): string
    {
        return 'push';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function send(Notifiable $notifiable, array $data): bool
    {
        $targets = $this->extractTargets($notifiable);
        if ($targets === []) {
            return false;
        }

        $payload = $this->format($data, $notifiable);
        $order = (array) ($this->config['default_order'] ?? ['fcm','apns','webpush']);

        $sent = false;
        foreach ($order as $driver) {
            if (!isset($targets[$driver]) || !$this->driverEnabled($driver)) {
                continue;
            }

            try {
                $ok = match ($driver) {
                    'fcm' => $this->sendFcm((array) $targets['fcm'], $payload),
                    'apns' => $this->sendApns((array) $targets['apns'], $payload),
                    'webpush' => $this->sendWebPush((array) $targets['webpush'], $payload),
                    default => false,
                };
                $sent = $sent || $ok;
            } catch (\Throwable $e) {
                $this->logger->error('Push send failed', [
                    'driver' => $driver,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function format(array $data, Notifiable $notifiable): array
    {
        return $this->formatter->format($data, $notifiable);
    }

    public function isAvailable(): bool
    {
        $drivers = (array) ($this->config['drivers'] ?? []);
        $anyEnabled = false;
        foreach (['fcm','apns','webpush'] as $d) {
            $enabled = (bool) ($drivers[$d]['enabled'] ?? false);
            $anyEnabled = $anyEnabled || $enabled;
        }
        return $anyEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    private function driverEnabled(string $driver): bool
    {
        return (bool) ($this->config['drivers'][$driver]['enabled'] ?? false);
    }

    /**
     * Extract target tokens/subscriptions from Notifiable.
     * Expected shape from routeNotificationFor('push'):
     *   [ 'fcm' => string|string[], 'apns' => string|string[], 'webpush' => array|array[] ]
     *
     * @return array<string, mixed>
     */
    private function extractTargets(Notifiable $notifiable): array
    {
        $targets = $notifiable->routeNotificationFor('push');
        if ($targets === null || $targets === '' || $targets === []) {
            return [];
        }
        if (!is_array($targets)) {
            // Back-compat: single token assumed to be FCM
            return ['fcm' => [$targets]];
        }
        return $targets;
    }

    /**
     * @param array<int, string>|array<string, mixed> $targets
     * @param array<string, mixed> $payload
     */
    private function sendFcm(array $targets, array $payload): bool
    {
        // Normalize tokens first
        $tokens = [];
        if (isset($targets['token'])) {
            $tokens = (array) $targets['token'];
        } elseif (is_array($targets) && isset($targets[0])) {
            $tokens = $targets;
        } elseif (is_string($targets)) {
            $tokens = [$targets];
        }
        $tokens = array_values(array_filter(array_map('strval', $tokens)));
        if ($tokens === []) {
            $this->logger->warning('No FCM tokens provided');
            return false;
        }

        // Require HTTP v1 configuration
        $creds = $this->config['drivers']['fcm']['credentials'] ?? null;
        $project = $this->config['drivers']['fcm']['project'] ?? null;
        if (empty($creds) || empty($project)) {
            $this->logger->error('FCM v1 configuration missing: set NOTIVA_FCM_CREDENTIALS and NOTIVA_FCM_PROJECT');
            return false;
        }

        return $this->sendFcmV1($tokens, $payload, (string) $project, (string) $creds);
    }

    /**
     * FCM HTTP v1 send
     * @param array<int,string> $tokens
     * @param array<string,mixed> $payload
     */
    private function sendFcmV1(array $tokens, array $payload, string $projectId, string $credentials): bool
    {
        try {
            $container = app($this->context);
            if (!$container->has(\Glueful\Http\Client::class)) {
                $this->logger->error('Glueful HTTP client unavailable; cannot send FCM v1 requests');
                return false;
            }
            /** @var \Glueful\Http\Client $http */
            $http = $container->get(\Glueful\Http\Client::class);

            $token = $this->getFcmAccessToken($http, $credentials);
            if ($token === null) {
                return false;
            }

            $successAny = false;
            $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';

            $notification = array_filter([
                'title' => $payload['title'] ?? null,
                'body' => $payload['body'] ?? null,
                'image' => $payload['image'] ?? null,
            ], fn($v) => $v !== null && $v !== '');

            foreach ($tokens as $t) {
                $body = [
                    'message' => array_filter([
                        'token' => $t,
                        'notification' => $notification,
                        'data' => (array) ($payload['data'] ?? []),
                        'android' => $this->buildAndroidOptions($payload),
                        'apns' => $this->buildApnsOptions($payload),
                    ])
                ];

                $resp = $http->post($endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                    'timeout' => 10,
                ]);

                if ($resp->isSuccessful()) {
                    $successAny = true;
                } else {
                    $this->logger->warning('FCM v1 send failed for token', [
                        'status' => $resp->getStatusCode(),
                        'token' => substr($t, 0, 8) . '…',
                        'body' => $resp->getBody(),
                    ]);
                }
            }

            return $successAny;
        } catch (\Throwable $e) {
            $this->logger->error('FCM v1 send exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obtain an access token for FCM using service account credentials.
     */
    private function getFcmAccessToken(\Glueful\Http\Client $http, string $credentials): ?string
    {
        if (!extension_loaded('openssl')) {
            $this->logger->error('OpenSSL extension required for FCM v1 JWT signing');
            return null;
        }

        $creds = $this->loadGoogleCredentials($credentials);
        if ($creds === null) {
            $this->logger->error('Invalid Google service account credentials');
            return null;
        }

        try {
            $jwt = $this->buildGoogleAssertion(
                (string) $creds['client_email'],
                (string) $creds['private_key'],
                'https://www.googleapis.com/auth/firebase.messaging',
                'https://oauth2.googleapis.com/token',
                3600
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sign JWT for FCM v1', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Use cached token if valid
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        $cacheKey = hash('sha256', (string) $creds['client_email'] . '|' . $scope);
        $cached = self::$fcmTokenCache[$cacheKey] ?? null;
        if (is_array($cached) && isset($cached['token'], $cached['exp']) && (int) $cached['exp'] > (time() + 30)) {
            return (string) $cached['token'];
        }

        try {
            $resp = $http->post('https://oauth2.googleapis.com/token', [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ],
                'timeout' => 10,
            ]);

            if (!$resp->isSuccessful()) {
                $this->logger->error('OAuth token request failed', [
                    'status' => $resp->getStatusCode(),
                    'body' => $resp->getBody(),
                ]);
                return null;
            }

            $json = $resp->json(true);
            if (!is_array($json) || empty($json['access_token'])) {
                return null;
            }
            $token = (string) $json['access_token'];
            $expiresIn = isset($json['expires_in']) ? (int) $json['expires_in'] : 3600;
            self::$fcmTokenCache[$cacheKey] = [
                'token' => $token,
                'exp' => time() + max(60, $expiresIn - 60), // refresh 60s early
            ];
            return $token;
        } catch (\Throwable $e) {
            $this->logger->error('OAuth token exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadGoogleCredentials(string $credentials)
    {
        // If it's a path, load file; otherwise assume raw JSON
        if (is_file($credentials) && is_readable($credentials)) {
            $json = file_get_contents($credentials);
            if ($json === false) {
                return null;
            }
        } else {
            $json = $credentials;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return null;
        }
        return $data;
    }

    /**
     * Build Android-specific options for FCM v1 message.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function buildAndroidOptions(array $payload): ?array
    {
        $notif = array_filter([
            'title' => $payload['title'] ?? null,
            'body' => $payload['body'] ?? null,
            'click_action' => $payload['click_action'] ?? null,
            'channel_id' => $payload['android_channel_id'] ?? $payload['channel_id'] ?? null,
            'sound' => $payload['sound'] ?? null,
            'icon' => $payload['icon'] ?? null,
            'color' => $payload['color'] ?? null,
            'tag' => $payload['tag'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $android = array_filter([
            'priority' => $payload['android_priority'] ?? null, // NORMAL, HIGH
            'ttl' => isset($payload['ttl']) ? (is_numeric($payload['ttl']) ? ((int)$payload['ttl']) . 's' : (string)$payload['ttl']) : null,
            'notification' => $notif !== [] ? $notif : null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        return $android !== [] ? $android : null;
    }

    /**
     * Build APNs-specific options for FCM v1 message.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function buildApnsOptions(array $payload): ?array
    {
        $aps = array_filter([
            'alert' => array_filter([
                'title' => $payload['title'] ?? null,
                'body' => $payload['body'] ?? null,
            ], fn($v) => $v !== null && $v !== ''),
            'sound' => $payload['sound'] ?? 'default',
            'category' => $payload['apns_category'] ?? $payload['category'] ?? null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        $headers = array_filter([
            'apns-priority' => $payload['apns_priority'] ?? null, // 10 (immediate) or 5 (background)
            'apns-push-type' => $payload['apns_push_type'] ?? 'alert',
        ], fn($v) => $v !== null && $v !== '');

        $apns = array_filter([
            'headers' => $headers !== [] ? $headers : null,
            'payload' => ['aps' => $aps],
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        return $apns !== [] ? $apns : null;
    }

    /**
     * Build Google service account assertion for OAuth token exchange.
     */
    private function buildGoogleAssertion(
        string $clientEmail,
        string $privateKey,
        string $scope,
        string $audience,
        int $ttl = 3600
    ): string {
        $now = time();
        $claims = [
            'iss' => $clientEmail,
            'scope' => $scope,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return \Glueful\Auth\JWTService::signRS256($claims, $privateKey);
    }

    /**
     * @param array<int, string>|array<string, mixed> $targets
     * @param array<string, mixed> $payload
     */
    private function sendApns(array $targets, array $payload): bool
    {
        if (!class_exists('Pushok\\ApnsClient')) {
            $this->logger->warning('APNs library not installed (edamov/pushok)');
            return false;
        }

        $cfg = (array)($this->config['drivers']['apns'] ?? []);
        $sandbox = (bool)($cfg['sandbox'] ?? true);
        $bundleId = (string)($cfg['app_bundle_id'] ?? ($cfg['bundle_id'] ?? ''));

        $usingToken = !empty($cfg['p8_path']) && !empty($cfg['key_id']) && !empty($cfg['team_id']) && !empty($bundleId);
        $usingCert = !empty($cfg['certificate']);

        if (!$usingToken && !$usingCert) {
            $this->logger->error('APNs configuration incomplete: provide token (p8,key_id,team_id,bundle_id) or certificate');
            return false;
        }

        try {
            if ($usingToken) {
                $auth = new \Pushok\AuthProvider\Token([
                    'key_id' => (string)$cfg['key_id'],
                    'team_id' => (string)$cfg['team_id'],
                    'app_bundle_id' => $bundleId,
                    'private_key_path' => (string)$cfg['p8_path'],
                    'private_key_secret' => null,
                ]);
            } else {
                $auth = new \Pushok\AuthProvider\Certificate(
                    (string)$cfg['certificate'],
                    (string)($cfg['passphrase'] ?? '')
                );
            }

            $client = new \Pushok\ApnsClient($auth, !$sandbox);

            // Build payload
            $alert = \Pushok\Payload\Alert::create();
            if (!empty($payload['title'])) {
                $alert->setTitle((string)$payload['title']);
            }
            if (!empty($payload['body'])) {
                $alert->setBody((string)$payload['body']);
            }

            $aps = \Pushok\Payload::create()->setAlert($alert);
            if (!empty($payload['sound'])) {
                $aps->setSound((string)$payload['sound']);
            }
            if (isset($payload['badge'])) {
                $aps->setBadge((int)$payload['badge']);
            }
            if (!empty($payload['category'])) {
                $aps->setCategory((string)$payload['category']);
            }
            if (isset($payload['data']) && is_array($payload['data'])) {
                $aps->setCustomValue('data', $payload['data']);
            }

            $pushType = (string)($payload['apns_push_type'] ?? 'alert'); // alert|background|voip etc.
            $priority = (int)($payload['apns_priority'] ?? 10); // 10 immediate, 5 background
            $collapseId = isset($payload['collapse_id']) ? (string)$payload['collapse_id'] : null;

            $notifications = [];
            // Normalize targets tokens
            $tokens = is_array($targets) && isset($targets[0]) ? $targets : (array)$targets;
            foreach ($tokens as $token) {
                if (!is_string($token) || $token === '') {
                    continue;
                }
                $n = new \Pushok\Notification($aps, $token);
                $n->setPushType($pushType);
                if ($bundleId !== '') {
                    $n->setTopic($bundleId);
                }
                if ($collapseId !== null) {
                    $n->setCollapseId($collapseId);
                }
                $n->setPriority(in_array($priority, [5,10], true) ? $priority : 10);
                $notifications[] = $n;
            }

            if ($notifications === []) {
                $this->logger->warning('No APNs tokens provided');
                return false;
            }

            $sender = new \Pushok\Sender($client, $notifications);
            $responses = $sender->send();

            $successAny = false;
            foreach ($responses as $response) {
                if ($response->isSuccessful()) {
                    $successAny = true;
                } else {
                    $this->logger->warning('APNs delivery failed', [
                        'status' => $response->getStatusCode(),
                        'reason' => $response->getErrorReason(),
                        'details' => $response->getErrorDescription(),
                    ]);
                }
            }

            return $successAny;
        } catch (\Throwable $e) {
            $this->logger->error('APNs exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $targets Each item is a Web Push subscription
     * @param array<string, mixed> $payload
     */
    private function sendWebPush(array $targets, array $payload): bool
    {
        if (!class_exists('Minishlink\\WebPush\\WebPush')) {
            $this->logger->warning('WebPush library not installed (minishlink/web-push)');
            return false;
        }

        $vapid = (array)($this->config['drivers']['webpush']['vapid'] ?? []);
        $publicKey = $vapid['public_key'] ?? null;
        $privateKey = $vapid['private_key'] ?? null;
        $subject = $vapid['subject'] ?? null; // mailto: or origin URL per spec

        if (!$publicKey || !$privateKey || !$subject) {
            $this->logger->error('WebPush VAPID configuration missing', [
                'has_public' => (bool)$publicKey,
                'has_private' => (bool)$privateKey,
                'has_subject' => (bool)$subject,
            ]);
            return false;
        }

        try {
            $webPush = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => (string)$subject,
                    'publicKey' => (string)$publicKey,
                    'privateKey' => (string)$privateKey,
                ],
            ]);

            // Build browser payload (client handles notification display)
            $body = [
                'title' => (string)($payload['title'] ?? ''),
                'body' => (string)($payload['body'] ?? ''),
                'icon' => $payload['icon'] ?? null,
                'image' => $payload['image'] ?? null,
                'badge' => $payload['badge'] ?? null,
                'data' => (array)($payload['data'] ?? []),
                'tag' => $payload['tag'] ?? null,
                'renotify' => $payload['renotify'] ?? null,
                'requireInteraction' => $payload['requireInteraction'] ?? null,
                'actions' => $payload['actions'] ?? null, // [{action,title,icon}]
            ];
            // Remove nulls
            $body = array_filter($body, fn($v) => $v !== null && $v !== '');
            $jsonPayload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false) {
                $jsonPayload = '{}';
            }

            $successAny = false;
            $ttl = isset($payload['ttl']) ? (int)$payload['ttl'] : 2419200; // 28 days default
            $urgency = $payload['urgency'] ?? null; // very-low | low | normal | high

            foreach ($targets as $sub) {
                // Expect shape: ['endpoint' => ..., 'keys' => ['p256dh' => ..., 'auth' => ...]]
                if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
                    $this->logger->warning('Invalid WebPush subscription format');
                    continue;
                }

                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => (string)$sub['endpoint'],
                    'publicKey' => (string)$sub['keys']['p256dh'],
                    'authToken' => (string)$sub['keys']['auth'],
                ]);

                $options = ['TTL' => $ttl];
                if (is_string($urgency) && in_array($urgency, ['very-low','low','normal','high'], true)) {
                    $options['urgency'] = $urgency;
                }

                try {
                    $webPush->sendOneNotification($subscription, $jsonPayload, $options);
                    $successAny = true; // Will be flipped to false if report shows failure
                } catch (\Throwable $e) {
                    $this->logger->warning('WebPush send error', [
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            // Flush and inspect reports
            foreach ($webPush->flush() as $report) {
                if (!$report->isSuccess()) {
                    $this->logger->warning('WebPush delivery failed', [
                        'endpoint' => $report->getRequest()->getUri(),
                        'reason' => $report->getReason(),
                    ]);
                }
            }

            return $successAny;
        } catch (\Throwable $e) {
            $this->logger->error('WebPush exception', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
