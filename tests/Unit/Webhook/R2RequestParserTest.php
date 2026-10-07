<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit\Webhook;

use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;
use Vadage\PresignedUploaderBundle\Webhook\AbstractSignedRequestParser;
use Vadage\PresignedUploaderBundle\Webhook\R2RequestParser;

final class R2RequestParserTest extends ParserTestCase
{
    public function testParsesObjectCreation(): void
    {
        $events = self::events((new R2RequestParser($this->clock))->parse($this->request([
            'account' => 'abc',
            'action' => 'PutObject',
            'bucket' => 'uploads',
            'object' => ['key' => 'avatars/a b.png', 'size' => 5, 'eTag' => 'etag'],
            'eventTime' => '2026-10-06T12:00:00Z',
        ]), self::SECRET));

        self::assertSame(AbstractSignedRequestParser::OBJECT_CREATED, $events[0]->getName());
        self::assertSame(['bucket' => 'uploads', 'key' => 'avatars/a b.png'], $events[0]->getPayload());
    }

    public function testIgnoresOtherActions(): void
    {
        $event = (new R2RequestParser($this->clock))->parse($this->request(['action' => 'DeleteObject', 'bucket' => 'b', 'object' => ['key' => 'k']]), self::SECRET);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertSame(AbstractSignedRequestParser::IGNORED, $event->getName());
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->expectException(RejectWebhookException::class);
        (new R2RequestParser($this->clock))->parse($this->request(['action' => 'PutObject'], 'wrong'), self::SECRET);
    }

    public function testRejectsReplayedRequests(): void
    {
        $request = $this->request(['action' => 'PutObject', 'bucket' => 'b', 'object' => ['key' => 'k']]);
        $this->clock->modify('+10 minutes');

        $this->expectException(RejectWebhookException::class);
        (new R2RequestParser($this->clock))->parse($request, self::SECRET);
    }

    public function testRejectsMalformedPayloads(): void
    {
        $this->expectException(RejectWebhookException::class);
        (new R2RequestParser($this->clock))->parse($this->request(['action' => 'PutObject', 'bucket' => 'b']), self::SECRET);
    }
}
