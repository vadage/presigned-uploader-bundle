<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

final readonly class ObjectMetadata
{
    public function __construct(
        public int $size,
        public ?string $mimeType,
        public ?string $sha256 = null,
    ) {
    }
}
