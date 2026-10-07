<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Vadage\PresignedUploaderBundle\Webhook\AbstractSignedRequestParser;

abstract class ParserTestCase extends TestCase
{
    protected const SECRET = 'secret';

    protected MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-06 12:00:00');
    }

    /**
     * @param array<mixed> $payload
     */
    protected function request(array $payload, string $secret = self::SECRET): Request
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $timestamp = $this->clock->now()->getTimestamp();

        return Request::create('/webhook/test', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => AbstractSignedRequestParser::sign($body, $timestamp, $secret),
        ], content: $body);
    }

    /**
     * @param RemoteEvent|array<RemoteEvent>|null $result
     *
     * @return list<RemoteEvent>
     */
    protected static function events(RemoteEvent|array|null $result): array
    {
        self::assertIsArray($result);

        return array_values($result);
    }
}
