<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;

final class CreatePresignedUploadInput
{
    public function __construct(
        #[ApiProperty(description: 'Name of the mapping, as in #[UploadableField(name: ...)]')]
        public string $mapping,
        #[ApiProperty(description: 'The file name on the uploader\'s device')]
        public string $filename,
        // GraphQL's Int has 32 bits
        #[ApiProperty(description: 'Size in bytes')]
        public float $size,
        public string $mimeType,
        #[ApiProperty(description: 'Base64 SHA-256 checksum; required for mappings with checksum: true')]
        public ?string $sha256 = null,
    ) {
    }
}
