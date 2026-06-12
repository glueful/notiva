<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use Glueful\Logging\LogManager;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\RichNotificationChannel;
use Glueful\Notifications\Results\NotificationResult;

/**
 * Push Notification Channel (FCM, APNs, Web Push)
 *
 * Implements {@see RichNotificationChannel}, so the framework dispatcher (1.51.0+) records a
 * structured {@see NotificationResult} per send — send latency plus per-driver outcome metadata
 * (`drivers_attempted` / `drivers_succeeded`) and stable error codes. Push is multi-target /
 * multi-driver, so no single provider message id is surfaced. The legacy {@see self::send()}
 * bool contract is preserved by delegating to {@see self::sendNotification()}.
 */
class PushChannel implements RichNotificationChannel
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
     * Send the push notification (legacy bool contract).
     *
     * Delegates to {@see self::sendNotification()} and collapses the structured result to a
     * bool, so existing `NotificationChannel::send()` callers are unaffected.
     *
     * @param array<string, mixed> $data
     */
    public function send(Notifiable $notifiable, array $data): bool
    {
        return $this->sendNotification($notifiable, $data)->success;
    }

    /**
     * Send the push notification and return a structured {@see NotificationResult}.
     *
     * Records send latency and per-driver outcomes (`drivers_attempted` / `drivers_succeeded` in
     * metadata), and maps failure modes to stable error codes: `no_targets` (no push routes —
     * non-retryable), `no_eligible_driver` (no enabled driver matched a target — non-retryable,
     * a config/targeting issue), and `all_drivers_failed` (every attempted driver failed —
     * retryable). Success is reported when any driver delivered to at least one target.
     *
     * @param array<string, mixed> $data
     */
    public function sendNotification(Notifiable $notifiable, array $data): NotificationResult
    {
        $targets = $this->extractTargets($notifiable);
        if ($targets === []) {
            return NotificationResult::failure(
                errorCode: 'no_targets',
                errorMessage: 'Notifiable has no push targets.',
                retryable: false
            );
        }

        $payload = $this->format($data, $notifiable);
        $order = (array) ($this->config['default_order'] ?? ['fcm','apns','webpush']);

        $start = microtime(true);
        $attempted = [];
        $succeeded = [];
        foreach ($order as $driver) {
            if (!isset($targets[$driver]) || !$this->driverEnabled($driver)) {
                continue;
            }

            $attempted[] = $driver;
            try {
                $ok = match ($driver) {
                    'fcm' => $this->sendFcm((array) $targets['fcm'], $payload),
                    'apns' => $this->sendApns((array) $targets['apns'], $payload),
                    'webpush' => $this->sendWebPush((array) $targets['webpush'], $payload),
                    default => false,
                };
                if ($ok) {
                    $succeeded[] = $driver;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Push send failed', [
                    'driver' => $driver,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        $metadata = ['drivers_attempted' => $attempted, 'drivers_succeeded' => $succeeded];

        if ($succeeded !== []) {
            return NotificationResult::success(latencyMs: $latencyMs, metadata: $metadata);
        }

        if ($attempted === []) {
            // No enabled driver had a matching target — a config/targeting problem, not transient.
            return NotificationResult::failure(
                errorCode: 'no_eligible_driver',
                errorMessage: 'No enabled push driver matched the notifiable targets.',
                retryable: false,
                latencyMs: $latencyMs,
                metadata: $metadata
            );
        }

        return NotificationResult::failure(
            errorCode: 'all_drivers_failed',
            errorMessage: 'All attempted push drivers failed to deliver.',
            retryable: true,
            latencyMs: $latencyMs,
            metadata: $metadata
        );
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
        // Normalize tokens first ($targets is always an array per the signature).
        $tokens = [];
        if (isset($targets['token'])) {
            $tokens = (array) $targets['token'];
        } elseif (isset($targets[0])) {
            $tokens = $targets;
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
                    // 404 = UNREGISTERED: the token is permanently dead.
                    // (400 can be a payload problem, so it is not treated as dead.)
                    if ($resp->getStatusCode() === 404) {
                        $this->invalidateDeviceToken('fcm', $t);
                    }
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

        // Use cached token if valid — checked before signing so the cache skips
        // the RS256 work, not just the token-exchange round trip.
        $scope = 'https://www.googleapis.com/auth/firebase.messaging';
        $cacheKey = hash('sha256', (string) $creds['client_email'] . '|' . $scope);
        $cached = self::$fcmTokenCache[$cacheKey] ?? null;
        if (is_array($cached) && (int) $cached['exp'] > (time() + 30)) {
            return (string) $cached['token'];
        }

        try {
            $jwt = $this->buildGoogleAssertion(
                (string) $creds['client_email'],
                (string) $creds['private_key'],
                $scope,
                'https://oauth2.googleapis.com/token',
                3600
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sign JWT for FCM v1', [
                'error' => $e->getMessage(),
            ]);
            return null;
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
     *
     * Always returns a non-empty array (it carries at least `payload.aps`).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildApnsOptions(array $payload): array
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

        return $apns;
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
        if (!class_exists(\Pushok\Client::class)) {
            $this->logger->warning('APNs library not installed (edamov/pushok)');
            return false;
        }

        $cfg = (array)($this->config['drivers']['apns'] ?? []);
        $sandbox = (bool)($cfg['sandbox'] ?? true);
        $bundleId = (string)($cfg['app_bundle_id'] ?? ($cfg['bundle_id'] ?? ''));

        $usingToken = !empty($cfg['p8_path']) && !empty($cfg['key_id']) && !empty($cfg['team_id']) && $bundleId !== '';
        $usingCert = !empty($cfg['certificate']);

        if (!$usingToken && !$usingCert) {
            $this->logger->error(
                'APNs configuration incomplete: provide token (p8_path,key_id,team_id,app_bundle_id) or certificate'
            );
            return false;
        }

        $tokens = $this->normalizeApnsTokens($targets);
        if ($tokens === []) {
            $this->logger->warning('No APNs tokens provided');
            return false;
        }

        try {
            // pushok auth providers use private constructors + ::create() factories. The APNs
            // topic is derived from the auth provider's app_bundle_id (no per-notification topic).
            $auth = $usingToken
                ? \Pushok\AuthProvider\Token::create([
                    'key_id' => (string)$cfg['key_id'],
                    'team_id' => (string)$cfg['team_id'],
                    'app_bundle_id' => $bundleId,
                    'private_key_path' => (string)$cfg['p8_path'],
                    'private_key_secret' => isset($cfg['passphrase']) ? (string)$cfg['passphrase'] : null,
                ])
                : \Pushok\AuthProvider\Certificate::create([
                    'certificate_path' => (string)$cfg['certificate'],
                    'certificate_secret' => (string)($cfg['passphrase'] ?? ''),
                    'app_bundle_id' => $bundleId !== '' ? $bundleId : null,
                ]);

            $client = new \Pushok\Client($auth, !$sandbox);

            $aps = $this->buildApnsPayload($payload);
            // apns-priority 5 is the only "low/background" value; everything else is immediate (10).
            $lowPriority = ((int)($payload['apns_priority'] ?? 10)) === 5;
            $collapseId = isset($payload['collapse_id']) ? (string)$payload['collapse_id'] : null;

            foreach ($tokens as $token) {
                $notification = new \Pushok\Notification($aps, $token);
                if ($collapseId !== null) {
                    $notification->setCollapseId($collapseId);
                }
                $lowPriority ? $notification->setLowPriority() : $notification->setHighPriority();
                $client->addNotification($notification);
            }

            $successAny = false;
            foreach ($client->push() as $response) {
                if ($response->getStatusCode() === 200) {
                    $successAny = true;
                } else {
                    $this->logger->warning('APNs delivery failed', [
                        'status' => $response->getStatusCode(),
                        'reason' => $response->getErrorReason(),
                        'details' => $response->getErrorDescription(),
                    ]);
                    // 410 Unregistered / BadDeviceToken: permanently dead token.
                    $reason = $response->getErrorReason();
                    $deadToken = $response->getDeviceToken();
                    if (
                        $deadToken !== null && $deadToken !== ''
                        && ($response->getStatusCode() === 410
                            || in_array($reason, ['Unregistered', 'BadDeviceToken'], true))
                    ) {
                        $this->invalidateDeviceToken('apns', $deadToken);
                    }
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
     * Build a pushok APNs {@see \Pushok\Payload} from our normalized push payload.
     *
     * Pure (no I/O) so it can be unit-tested without APNs credentials. In pushok 0.19 the push
     * type lives on the payload (not the notification).
     *
     * @param array<string, mixed> $payload
     */
    private function buildApnsPayload(array $payload): \Pushok\Payload
    {
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
        $aps->setPushType((string)($payload['apns_push_type'] ?? 'alert')); // alert|background|voip…

        return $aps;
    }

    /**
     * Normalize APNs target tokens (list or assoc) to a clean list of non-empty strings.
     *
     * @param array<int, string>|array<string, mixed> $targets
     * @return list<string>
     */
    private function normalizeApnsTokens(array $targets): array
    {
        $out = [];
        foreach ($targets as $token) {
            if (is_string($token) && $token !== '') {
                $out[] = $token;
            }
        }
        return $out;
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
                    // sendOneNotification() flushes immediately and returns the delivery report.
                    $report = $webPush->sendOneNotification($subscription, $jsonPayload, $options);
                    if ($report->isSuccess()) {
                        $successAny = true;
                    } else {
                        $this->logger->warning('WebPush delivery failed', [
                            'endpoint' => (string) $report->getRequest()->getUri(),
                            'reason' => $report->getReason(),
                            'expired' => $report->isSubscriptionExpired(),
                        ]);
                        if ($report->isSubscriptionExpired()) {
                            $this->invalidateDeviceToken(
                                'webpush',
                                DeviceRegistry::webPushToken((string) $sub['endpoint'])
                            );
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('WebPush send error', [
                        'message' => $e->getMessage(),
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

    /**
     * Delivery feedback loop: mark a provider-reported dead token invalid in the
     * device registry so it is not retried forever. Failure-safe — apps that route
     * pushes from their own token store (not push_devices) simply see no matching
     * rows, and registry errors never break the send path.
     */
    private function invalidateDeviceToken(string $provider, string $token): void
    {
        try {
            $container = app($this->context);
            if (!$container->has(DeviceRegistry::class)) {
                return;
            }
            /** @var DeviceRegistry $registry */
            $registry = $container->get(DeviceRegistry::class);
            $invalidated = $registry->invalidateToken($provider, $token);
            if ($invalidated > 0) {
                $this->logger->info('Invalidated dead push token', [
                    'provider' => $provider,
                    'token' => substr($token, 0, 8) . '…',
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to invalidate dead push token', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
