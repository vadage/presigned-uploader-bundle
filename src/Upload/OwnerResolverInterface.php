<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

/**
 * Identifies who an upload belongs to. Only the owner can verify and claim it.
 */
interface OwnerResolverInterface
{
    public function resolve(): string;
}
