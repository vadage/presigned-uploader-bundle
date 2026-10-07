<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit\Webhook;

use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Vadage\PresignedUploaderBundle\Webhook\AbstractSignedRequestParser;
use Vadage\PresignedUploaderBundle\Webhook\S3EventRequestParser;

final class S3EventRequestParserTest extends ParserTestCase
{
    public function testParsesCreatedRecordsOnly(): void
    {
        $events = self::events((new S3EventRequestParser($this->clock))->parse($this->request(['Records' => [
            ['eventName' => 'ObjectCreated:Put', 's3' => ['bucket' => ['name' => 'uploads'], 'object' => ['key' => 'avatars/a+b%C3%A4.png', 'sequencer' => '1']]],
            ['eventName' => 'ObjectRemoved:Delete', 's3' => ['bucket' => ['name' => 'uploads'], 'object' => ['key' => 'x']]],
        ]]), self::SECRET));

        self::assertCount(1, $events);
        self::assertSame(['bucket' => 'uploads', 'key' => 'avatars/a bä.png'], $events[0]->getPayload());
    }

    public function testIgnoresNotificationsWithoutCreatedObjects(): void
    {
        $event = (new S3EventRequestParser($this->clock))->parse($this->request(['Records' => []]), self::SECRET);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertSame(AbstractSignedRequestParser::IGNORED, $event->getName());
    }

    public function testRejectsOtherPayloads(): void
    {
        $this->expectException(RejectWebhookException::class);
        (new S3EventRequestParser($this->clock))->parse($this->request(['action' => 'PutObject']), self::SECRET);
    }
}
