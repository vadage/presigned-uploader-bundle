<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Messenger;

/**
 * A storage reported a new object. Dispatch it from your own queue consumer (SQS, a relay, ...) to verify
 * uploads as soon as they arrive; objects that were not presigned by the bundle are ignored.
 */
final readonly class ObjectCreated
{
    public function __construct(
        public string $bucket,
        public string $key,
    ) {
    }
}
