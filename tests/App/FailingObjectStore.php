<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App;

use Vadage\PresignedUploaderBundle\Storage\ObjectStoreInterface;

/**
 * Records deletions, or fails them while $failing is set.
 */
final class FailingObjectStore implements ObjectStoreInterface
{
    public bool $failing = true;

    /** @var list<string> */
    public array $deleted = [];

    public function getName(): string
    {
        return 'private';
    }

    public function readStream(string $key): never
    {
        throw new \LogicException('Not used.');
    }

    public function writeStream(string $key, $contents, string $mimeType): never
    {
        throw new \LogicException('Not used.');
    }

    public function delete(string $key): void
    {
        if ($this->failing) {
            throw new \RuntimeException('down');
        }
        $this->deleted[] = $key;
    }

    public function copyFrom(ObjectStoreInterface $source, string $sourceKey, string $key): never
    {
        throw new \LogicException('Not used.');
    }

    public function url(string $key, ?\DateTimeImmutable $expiresAt = null): never
    {
        throw new \LogicException('Not used.');
    }
}
