<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Event;

use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

/**
 * The upload was attached to an entity or DTO, the object is at its final location.
 *
 * Dispatched once the reference is stored: after the Doctrine flush, or by UploadManager::commitClaim().
 * When the flush runs inside an outer transaction, that transaction may still roll back.
 */
final readonly class UploadClaimedEvent
{
    public function __construct(
        public PendingUpload $upload,
        public UploadMapping $mapping,
        public StoredObject $object,
    ) {
    }
}
