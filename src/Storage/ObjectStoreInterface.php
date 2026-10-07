<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

/**
 * Operations on the objects of a configured storage once they are uploaded.
 *
 * Implemented by S3Storage (the default) and FlysystemObjectStore (storages with a "filesystem").
 */
interface ObjectStoreInterface
{
    /**
     * The storage's name in the bundle configuration.
     */
    public function getName(): string;

    /**
     * @return resource
     */
    public function readStream(string $key);

    /**
     * @param resource $contents
     */
    public function writeStream(string $key, $contents, string $mimeType): void;

    /**
     * Deleting an object that does not exist is not an error.
     */
    public function delete(string $key): void;

    /**
     * Copies an object of another store into this one without downloading it, when both are backed by the
     * same account or filesystem.
     *
     * @return bool false when this store cannot copy from $source server-side; nothing was copied then
     */
    public function copyFrom(self $source, string $sourceKey, string $key): bool;

    /**
     * The storage's public URL for the key when one is configured, a temporary URL otherwise.
     */
    public function url(string $key, ?\DateTimeImmutable $expiresAt = null): string;
}
