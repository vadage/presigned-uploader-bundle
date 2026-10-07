<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Mapping;

/**
 * Resolved configuration of one #[UploadableField].
 */
final readonly class UploadMapping
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $property,
        public string $storage,
        public string $namer,
        public string $prefix,
        public bool $checksum,
        public ?StagingConfig $staging,
        public bool $sniffContent,
        public int $uploadTtl,
        public bool $deleteOnRemove,
        public bool $deleteOnReplace,
        /** Largest accepted upload in bytes, on top of the "presign" constraints; null when only those limit it. */
        public ?int $maxSize = null,
    ) {
    }
}
