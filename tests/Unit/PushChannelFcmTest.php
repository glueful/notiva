<?php

declare(strict_types=1);

namespace Glueful\Extensions\Notiva\Tests\Unit;

use Glueful\Extensions\Notiva\PushChannel;
use Glueful\Extensions\Notiva\PushFormatter;
use Glueful\Notifications\Contracts\Notifiable;
use PHPUnit\Framework\TestCase;

/**
 * The FCM message parts that do not need credentials: the `data` map FCM accepts, and the
 * title a notification's subject provides.
 */
final class PushChannelFcmTest extends TestCase
{
    /**
     * @param array<int|string, mixed> $data
     * @return array<string, string>
     */
    private function fcmData(array $data): array
    {
        $channel = (new \ReflectionClass(PushChannel::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(PushChannel::class, 'fcmData');
        $m->setAccessible(true);
        /** @var array<string, string> $result */
        $result = $m->invoke($channel, $data);
        return $result;
    }

    public function testDataValuesBecomeStringsForFcm(): void
    {
        $data = $this->fcmData([
            'parp_uuid' => 'abc123',
            'count' => 3,
            'ratio' => 0.5,
            'urgent' => true,
            'muted' => false,
            'actor' => ['uuid' => 'u1', 'name' => 'Ama'],
            'missing' => null,
        ]);

        self::assertSame([
            'parp_uuid' => 'abc123',
            'count' => '3',
            'ratio' => '0.5',
            'urgent' => 'true',
            'muted' => 'false',
            'actor' => '{"uuid":"u1","name":"Ama"}',
        ], $data);
    }

    public function testTheSubjectBecomesTheTitle(): void
    {
        $notifiable = $this->createStub(Notifiable::class);

        $push = (new PushFormatter())->format(['subject' => 'Ama started following you.'], $notifiable);

        self::assertSame('Ama started following you.', $push['title']);
    }
}
