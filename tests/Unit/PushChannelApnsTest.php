<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Tests\Unit;

use Glueful\Extensions\Notiva\PushChannel;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Results\NotificationResult;
use PHPUnit\Framework\TestCase;
use Pushok\Payload;

/**
 * Unit coverage for the APNs payload-building and the RichNotificationChannel result contract.
 *
 * These exercise the pure parts of {@see PushChannel} (no APNs credentials / network needed).
 * The channel is created without its constructor so we don't have to boot the framework — the
 * methods under test depend only on their arguments.
 */
final class PushChannelApnsTest extends TestCase
{
    private PushChannel $channel;

    protected function setUp(): void
    {
        $this->channel = (new \ReflectionClass(PushChannel::class))->newInstanceWithoutConstructor();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildApnsPayload(array $payload): Payload
    {
        $m = new \ReflectionMethod(PushChannel::class, 'buildApnsPayload');
        $m->setAccessible(true);
        /** @var Payload $result */
        $result = $m->invoke($this->channel, $payload);
        return $result;
    }

    /**
     * @param array<int|string, mixed> $targets
     * @return list<string>
     */
    private function normalizeApnsTokens(array $targets): array
    {
        $m = new \ReflectionMethod(PushChannel::class, 'normalizeApnsTokens');
        $m->setAccessible(true);
        /** @var list<string> $result */
        $result = $m->invoke($this->channel, $targets);
        return $result;
    }

    public function testBuildApnsPayloadMapsAllFields(): void
    {
        $aps = $this->buildApnsPayload([
            'title' => 'Hello',
            'body' => 'World',
            'sound' => 'chime.caf',
            'badge' => 3,
            'category' => 'MESSAGE',
            'data' => ['order_id' => 42],
            'apns_push_type' => 'alert',
        ]);

        $alert = $aps->getAlert();
        self::assertInstanceOf(\Pushok\Payload\Alert::class, $alert);
        self::assertSame('Hello', $alert->getTitle());
        self::assertSame('World', $alert->getBody());
        self::assertSame('chime.caf', $aps->getSound());
        self::assertSame(3, $aps->getBadge());
        self::assertSame('MESSAGE', $aps->getCategory());
        self::assertSame('alert', $aps->getPushType());
        self::assertSame(['order_id' => 42], $aps->getCustomValue('data'));
    }

    public function testBuildApnsPayloadDefaultsPushTypeToAlert(): void
    {
        $aps = $this->buildApnsPayload(['title' => 'Hi']);

        self::assertSame('alert', $aps->getPushType());
        // Optional fields stay unset when absent from the payload.
        self::assertNull($aps->getBadge());
        self::assertNull($aps->getCategory());
    }

    public function testBuildApnsPayloadHonoursBackgroundPushType(): void
    {
        $aps = $this->buildApnsPayload(['apns_push_type' => 'background']);

        self::assertSame('background', $aps->getPushType());
    }

    public function testNormalizeApnsTokensKeepsNonEmptyStringsFromAList(): void
    {
        self::assertSame(['a', 'b'], $this->normalizeApnsTokens(['a', 'b']));
    }

    public function testNormalizeApnsTokensDropsEmptyAndNonStringValues(): void
    {
        /** @var array<int, mixed> $mixed */
        $mixed = ['tok', '', null, 123, ['nested'], 'tok2'];
        self::assertSame(['tok', 'tok2'], $this->normalizeApnsTokens($mixed));
    }

    public function testNormalizeApnsTokensReadsValuesFromAnAssocArray(): void
    {
        self::assertSame(['ios-token'], $this->normalizeApnsTokens(['device' => 'ios-token']));
    }

    public function testNormalizeApnsTokensReturnsEmptyForNoTokens(): void
    {
        self::assertSame([], $this->normalizeApnsTokens([]));
    }

    public function testSendNotificationFailsClosedWithoutTargets(): void
    {
        $result = $this->channel->sendNotification($this->notifiableWithPushRoute(null), []);

        self::assertInstanceOf(NotificationResult::class, $result);
        self::assertFalse($result->success);
        self::assertSame('no_targets', $result->errorCode);
        self::assertFalse($result->retryable, 'A missing push route is a config/targeting issue, not transient.');
    }

    private function notifiableWithPushRoute(mixed $pushRoute): Notifiable
    {
        return new class ($pushRoute) implements Notifiable {
            public function __construct(private mixed $pushRoute)
            {
            }

            public function routeNotificationFor(string $channel): mixed
            {
                return $channel === 'push' ? $this->pushRoute : null;
            }

            public function getNotifiableId(): string
            {
                return 'test-id';
            }

            public function getNotifiableType(): string
            {
                return 'test';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return true;
            }

            /**
             * @return array<string, mixed>
             */
            public function getNotificationPreferences(): array
            {
                return [];
            }
        };
    }
}
