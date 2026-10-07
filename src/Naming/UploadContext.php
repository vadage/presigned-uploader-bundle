<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Naming;

use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;

/**
 * What a namer knows about the upload it names. The file name and type are client input, validated against
 * the mapping's constraints but not trustworthy beyond that.
 */
final readonly class UploadContext
{
    /**
     * @param string $ownerId who uploads, as returned by the OwnerResolverInterface (e.g. "user:alice")
     */
    public function __construct(
        public UploadMapping $mapping,
        public UploadDescriptor $descriptor,
        public string $ownerId,
    ) {
    }
}
