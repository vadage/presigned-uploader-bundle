<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Event;

use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;

/**
 * The object exists in the storage and passed verification. Dispatched once per upload,
 * no matter whether the browser callback or the storage webhook arrived first.
 */
final readonly class UploadVerifiedEvent
{
    public function __construct(
        public PendingUpload $upload,
        public UploadMapping $mapping,
    ) {
    }
}
