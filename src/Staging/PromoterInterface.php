<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Staging;

use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Storage\ObjectStoreInterface;

/**
 * Moves a staged upload to its final location when it is claimed, e.g. by copying or re-encoding it.
 *
 * Reads $upload->getKey() from $staging and writes $upload->getTargetKey() to $target. The bundle deletes
 * the staging object once the claim is committed.
 */
interface PromoterInterface
{
    public function promote(PendingUpload $upload, ObjectStoreInterface $staging, ObjectStoreInterface $target): void;
}
