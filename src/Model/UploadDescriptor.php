<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Model;

/**
 * What the client announces (before the upload) or the storage holds (after it); validated by "presign" constraints.
 */
final readonly class UploadDescriptor
{
    /**
     * @param string|null $sha256 base64 encoded
     */
    public function __construct(
        public string $filename,
        public int $size,
        public string $mimeType,
        public ?string $sha256 = null,
    ) {
    }

    public function withMimeType(string $mimeType): self
    {
        return new self($this->filename, $this->size, $mimeType, $this->sha256);
    }
}
