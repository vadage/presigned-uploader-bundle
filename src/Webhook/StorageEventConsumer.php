<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Webhook;

use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;

/**
 * @internal
 */
final readonly class StorageEventConsumer implements ConsumerInterface
{
    public function __construct(private UploadVerifier $verifier)
    {
    }

    public function consume(RemoteEvent $event): void
    {
        $payload = $event->getPayload();
        if (AbstractSignedRequestParser::OBJECT_CREATED !== $event->getName() || !\is_string($payload['bucket'] ?? null) || !\is_string($payload['key'] ?? null)) {
            return;
        }

        $this->verifier->verifyLocation($payload['bucket'], $payload['key']);
    }
}
