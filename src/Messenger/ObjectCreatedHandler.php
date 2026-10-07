<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Messenger;

use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;

/**
 * @internal
 */
final readonly class ObjectCreatedHandler
{
    public function __construct(private UploadVerifier $verifier)
    {
    }

    public function __invoke(ObjectCreated $message): void
    {
        $this->verifier->verifyLocation($message->bucket, $message->key);
    }
}
