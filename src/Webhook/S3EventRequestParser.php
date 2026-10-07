<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Webhook;

use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * S3 event notifications ({"Records": [...]}) as emitted by AWS S3 and S3-compatible storages,
 * forwarded by a relay that signs them (AWS itself delivers through SNS/EventBridge/SQS).
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html
 */
final class S3EventRequestParser extends AbstractSignedRequestParser
{
    protected function parsePayload(array $payload): array
    {
        if (!\is_array($payload['Records'] ?? null)) {
            throw new RejectWebhookException(400, 'Invalid S3 event notification.');
        }

        $events = [];
        foreach ($payload['Records'] as $record) {
            if (!\is_array($record) || !\is_string($record['eventName'] ?? null) || !str_starts_with($record['eventName'], 'ObjectCreated:')) {
                continue;
            }
            $s3 = \is_array($record['s3'] ?? null) ? $record['s3'] : [];
            $bucket = \is_array($s3['bucket'] ?? null) ? $s3['bucket']['name'] ?? null : null;
            $object = \is_array($s3['object'] ?? null) ? $s3['object'] : [];
            if (!\is_string($bucket) || !\is_string($object['key'] ?? null)) {
                throw new RejectWebhookException(400, 'Invalid S3 event notification.');
            }

            // Keys are URL-encoded, with "+" for spaces.
            $key = urldecode($object['key']);
            $sequencer = \is_string($object['sequencer'] ?? null) ? $object['sequencer'] : '';
            $events[] = self::objectCreated($bucket, $key, $bucket.'|'.$key.'|'.$sequencer);
        }

        return $events;
    }
}
