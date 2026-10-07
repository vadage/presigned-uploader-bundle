<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Event;

use Symfony\Component\Validator\ConstraintViolationListInterface;
use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;

/**
 * The stored object did not match what was validated before signing, it has been deleted.
 */
final readonly class UploadRejectedEvent
{
    public function __construct(
        public PendingUpload $upload,
        public UploadMapping $mapping,
        public ConstraintViolationListInterface $violations,
    ) {
    }
}
