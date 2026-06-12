<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Tests\Unit;

use Glueful\Database\Connection;
use Glueful\Extensions\Notiva\Services\DeviceRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * DeviceRegistry tests on a lightweight SQLite harness.
 *
 * The ownership tests are the important ones: every operation must be scoped to
 * the authenticated user passed by the controller, and client-supplied
 * user_uuid (JSON body or query string) must never override it.
 */
final class DeviceRegistryTest extends TestCase
{
    private Connection $connection;
    private DeviceRegistry $registry;
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = sys_get_temp_dir() . '/notiva-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->connection->getPDO()->exec('CREATE TABLE push_devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid TEXT,
            user_uuid TEXT,
            notifiable_type TEXT,
            notifiable_id TEXT,
            provider TEXT,
            platform TEXT,
            device_token TEXT,
            subscription_json TEXT,
            device_id TEXT,
            app_id TEXT,
            bundle_id TEXT,
            locale TEXT,
            timezone TEXT,
            status TEXT,
            registered_at TEXT,
            last_seen_at TEXT,
            invalidated_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $this->registry = new DeviceRegistry($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->dbPath) && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body, string $method = 'POST', string $query = ''): Request
    {
        $request = Request::create('/notiva/devices' . ($query !== '' ? '?' . $query : ''), $method, [], [], [], [], (string) json_encode($body));
        $request->headers->set('Content-Type', 'application/json');
        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data);
        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $where = '1=1'): array
    {
        $stmt = $this->connection->getPDO()->query("SELECT * FROM push_devices WHERE $where");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testRegisterStoresDeviceForAuthenticatedUser(): void
    {
        $response = $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'platform' => 'android', 'device_token' => 'tok-1']),
            'user-aaa'
        );

        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('user-aaa', $rows[0]['user_uuid']);
        $this->assertSame('active', $rows[0]['status']);
    }

    public function testRegisterIgnoresClientSuppliedUserUuid(): void
    {
        $this->registry->register(
            $this->jsonRequest([
                'provider' => 'fcm',
                'device_token' => 'tok-spoof',
                'user_uuid' => 'victim-uuid',
            ]),
            'attacker-uuid'
        );

        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('attacker-uuid', $rows[0]['user_uuid']);
    }

    public function testRegisterUpsertsExistingProviderToken(): void
    {
        $first = $this->decode($this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'tok-same']),
            'user-aaa'
        ));
        $second = $this->decode($this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'tok-same', 'platform' => 'android']),
            'user-aaa'
        ));

        $this->assertCount(1, $this->rows());
        $this->assertSame($first['data']['uuid'], $second['data']['uuid']);
        $this->assertSame('android', $this->rows()[0]['platform']);
    }

    public function testRotationInvalidatesOldTokenForSameDeviceId(): void
    {
        $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'tok-old', 'device_id' => 'phone-1']),
            'user-aaa'
        );
        $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'tok-new', 'device_id' => 'phone-1']),
            'user-aaa'
        );

        $old = $this->rows("device_token = 'tok-old'");
        $new = $this->rows("device_token = 'tok-new'");
        $this->assertSame('invalid', $old[0]['status']);
        $this->assertNotNull($old[0]['invalidated_at']);
        $this->assertSame('active', $new[0]['status']);
    }

    public function testRegisterWithoutDeviceIdLeavesOtherDevicesActive(): void
    {
        $this->registry->register(
            $this->jsonRequest(['provider' => 'apns', 'device_token' => 'iphone-token']),
            'user-aaa'
        );
        $this->registry->register(
            $this->jsonRequest(['provider' => 'apns', 'device_token' => 'ipad-token']),
            'user-aaa'
        );

        $this->assertCount(2, $this->rows("status = 'active'"));
    }

    public function testListReturnsOnlyAuthenticatedUsersDevicesIgnoringQueryUserUuid(): void
    {
        $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'mine']),
            'user-me'
        );
        $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'theirs']),
            'user-victim'
        );

        $response = $this->registry->list(
            Request::create('/notiva/devices?user_uuid=user-victim', 'GET'),
            'user-me'
        );

        $data = $this->decode($response);
        $devices = $data['data']['devices'];
        $this->assertCount(1, $devices);
        $this->assertSame('mine', $devices[0]['device_token']);
    }

    public function testUnregisterCannotTouchAnotherUsersDevice(): void
    {
        $victim = $this->decode($this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'victim-token']),
            'user-victim'
        ));

        $response = $this->registry->unregister(
            $this->jsonRequest([
                'uuid' => $victim['data']['uuid'],
                'user_uuid' => 'user-victim',
            ], 'DELETE'),
            'user-attacker'
        );

        $data = $this->decode($response);
        $this->assertSame(0, $data['data']['affected']);
        $this->assertSame('active', $this->rows("device_token = 'victim-token'")[0]['status']);
    }

    public function testUnregisterRevokesOwnDevice(): void
    {
        $own = $this->decode($this->registry->register(
            $this->jsonRequest(['provider' => 'fcm', 'device_token' => 'own-token']),
            'user-me'
        ));

        $response = $this->registry->unregister(
            $this->jsonRequest(['uuid' => $own['data']['uuid']], 'DELETE'),
            'user-me'
        );

        $data = $this->decode($response);
        $this->assertSame(1, $data['data']['affected']);
        $this->assertSame('revoked', $this->rows("device_token = 'own-token'")[0]['status']);
    }

    public function testWebPushRegistrationDerivesTokenFromEndpointHash(): void
    {
        $response = $this->registry->register(
            $this->jsonRequest([
                'provider' => 'webpush',
                'subscription' => [
                    'endpoint' => 'https://push.example.com/sub/abc',
                    'keys' => ['p256dh' => 'pk', 'auth' => 'at'],
                ],
            ]),
            'user-aaa'
        );

        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertStringStartsWith('wp_', (string) $rows[0]['device_token']);
        $this->assertNotEmpty($rows[0]['subscription_json']);
    }

    public function testRegisterRejectsInvalidProvider(): void
    {
        $response = $this->registry->register(
            $this->jsonRequest(['provider' => 'smoke-signals', 'device_token' => 'x']),
            'user-aaa'
        );
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testRegisterRequiresDeviceToken(): void
    {
        $response = $this->registry->register(
            $this->jsonRequest(['provider' => 'fcm']),
            'user-aaa'
        );
        $this->assertSame(422, $response->getStatusCode());
    }
}
