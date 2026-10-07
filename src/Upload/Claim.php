<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

/**
 * An upload in state Claiming: promoted to its final location, but not committed yet.
 *
 * Commit it with UploadManager::commitClaim() once the reference is stored. A claim that is never
 * committed falls back to Verified when its lease runs out, and the upload expires as usual.
 */
final readonly class Claim
{
    public function __construct(
        public PendingUpload $upload,
        public UploadMapping $mapping,
        public StoredObject $object,
    ) {
    }
}
