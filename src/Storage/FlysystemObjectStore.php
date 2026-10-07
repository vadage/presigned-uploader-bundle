<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

use League\Flysystem\FilesystemOperator;
use Psr\Clock\ClockInterface;

/**
 * Object operations through a Flysystem filesystem, e.g. one configured with league/flysystem-bundle.
 *
 * Keys are paths in the filesystem. When the same storage also receives presigned uploads, the filesystem
 * must address the same bucket without a path prefix of its own.
 */
final readonly class FlysystemObjectStore implements ObjectStoreInterface
{
    public function __construct(
        private string $name,
        private FilesystemOperator $filesystem,
        private ClockInterface $clock,
        private ?string $publicUrl = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function readStream(string $key)
    {
        return $this->filesystem->readStream($key);
    }

    public function writeStream(string $key, $contents, string $mimeType): void
    {
        // "ContentType" is forwarded by the S3 adapters; others detect the type themselves.
        $this->filesystem->writeStream($key, $contents, ['ContentType' => $mimeType]);
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }

    public function copyFrom(ObjectStoreInterface $source, string $sourceKey, string $key): bool
    {
        if (!$source instanceof self || $source->filesystem !== $this->filesystem) {
            return false;
        }

        $this->filesystem->copy($sourceKey, $key);

        return true;
    }

    public function url(string $key, ?\DateTimeImmutable $expiresAt = null): string
    {
        if (null !== $this->publicUrl) {
            return rtrim($this->publicUrl, '/').'/'.implode('/', array_map(rawurlencode(...), explode('/', $key)));
        }

        return $this->filesystem->temporaryUrl($key, $expiresAt ?? $this->clock->now()->modify('+5 minutes'));
    }
}
