<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Naming;

/**
 * Generates the storage key of an upload, including the mapping's prefix.
 *
 * Keys must be unique: never derive them from the client's file name alone, it is user input.
 */
interface NamerInterface
{
    public function name(UploadContext $context): string;
}
