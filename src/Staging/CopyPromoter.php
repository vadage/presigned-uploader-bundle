<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Staging;

use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Storage\ObjectStoreInterface;

/**
 * Copies the staged object unchanged: server-side when both storages share an account or filesystem,
 * streamed through the application otherwise.
 */
final class CopyPromoter implements PromoterInterface
{
    public function promote(PendingUpload $upload, ObjectStoreInterface $staging, ObjectStoreInterface $target): void
    {
        if ($target->copyFrom($staging, $upload->getKey(), $upload->getTargetKey())) {
            return;
        }

        $stream = $staging->readStream($upload->getKey());
        try {
            $target->writeStream($upload->getTargetKey(), $stream, $upload->getMimeType());
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
