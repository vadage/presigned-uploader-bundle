<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Webhook;

use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Cloudflare R2 event notification messages, forwarded from the queue by a consumer worker.
 *
 * @see https://developers.cloudflare.com/r2/buckets/event-notifications/
 */
final class R2RequestParser extends AbstractSignedRequestParser
{
    private const CREATE_ACTIONS = ['PutObject', 'CopyObject', 'CompleteMultipartUpload'];

    protected function parsePayload(array $payload): array
    {
        if (!\in_array($payload['action'] ?? null, self::CREATE_ACTIONS, true)) {
            return [];
        }

        $object = $payload['object'] ?? null;
        if (!\is_string($payload['bucket'] ?? null) || !\is_array($object) || !\is_string($object['key'] ?? null)) {
            throw new RejectWebhookException(400, 'Invalid R2 event notification.');
        }
        $eTag = \is_string($object['eTag'] ?? null) ? $object['eTag'] : '';
        $time = \is_string($payload['eventTime'] ?? null) ? $payload['eventTime'] : '';

        return [self::objectCreated($payload['bucket'], $object['key'], $payload['bucket'].'|'.$object['key'].'|'.$eTag.'|'.$time)];
    }
}
