<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Webhook;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Verifies the signed envelope the relay (e.g. a queue consumer worker) adds to forwarded storage events:
 * X-Webhook-Timestamp (unix time) and X-Webhook-Signature: hex(HMAC-SHA256(secret, "<timestamp>.<body>")).
 * Subclasses only translate the provider's payload.
 */
abstract class AbstractSignedRequestParser extends AbstractRequestParser
{
    public const OBJECT_CREATED = 'object_created';
    public const IGNORED = 'ignored';

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $tolerance = 300,
    ) {
    }

    public static function sign(string $body, int $timestamp, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return list<RemoteEvent> object_created events, empty for events to ignore
     */
    abstract protected function parsePayload(array $payload): array;

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([new MethodRequestMatcher('POST'), new IsJsonRequestMatcher()]);
    }

    /**
     * @return RemoteEvent|list<RemoteEvent>
     */
    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent|array
    {
        if ('' === $secret) {
            throw new RejectWebhookException(500, 'No webhook secret configured.');
        }
        $timestamp = (string) $request->headers->get('X-Webhook-Timestamp');
        if (!ctype_digit($timestamp) || abs($this->clock->now()->getTimestamp() - (int) $timestamp) > $this->tolerance) {
            throw new RejectWebhookException(401, 'Missing or expired timestamp.');
        }
        if (!hash_equals(self::sign($request->getContent(), (int) $timestamp, $secret), (string) $request->headers->get('X-Webhook-Signature'))) {
            throw new RejectWebhookException(401, 'Invalid signature.');
        }

        $payload = $request->toArray();
        $events = $this->parsePayload($payload);

        // An empty result is answered as rejected, which would make the sender retry forever.
        return [] !== $events ? $events : new RemoteEvent(self::IGNORED, hash('sha256', $request->getContent()), $payload);
    }

    /**
     * @param string $eventId unique per storage event, used for deduplication
     */
    protected static function objectCreated(string $bucket, string $key, string $eventId): RemoteEvent
    {
        return new RemoteEvent(self::OBJECT_CREATED, hash('sha256', $eventId), ['bucket' => $bucket, 'key' => $key]);
    }
}
