<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

use Psr\Container\ContainerInterface;

final readonly class StorageRegistry
{
    /**
     * @param ContainerInterface    $uploads storage name => S3Storage, for storages that receive presigned uploads
     * @param ContainerInterface    $objects storage name => ObjectStoreInterface, for every storage
     * @param array<string, string> $buckets storage name => bucket, for storages that receive presigned uploads
     */
    public function __construct(
        private ContainerInterface $uploads,
        private ContainerInterface $objects,
        private array $buckets,
    ) {
    }

    /**
     * The S3 side of a storage: presigning and verifying uploads.
     *
     * @internal
     */
    public function uploads(string $name): S3Storage
    {
        $storage = $this->uploads->get($name);
        \assert($storage instanceof S3Storage);

        return $storage;
    }

    public function objects(string $name): ObjectStoreInterface
    {
        $store = $this->objects->get($name);
        \assert($store instanceof ObjectStoreInterface);

        return $store;
    }

    /**
     * @return list<string>
     */
    public function namesForBucket(string $bucket): array
    {
        return array_keys($this->buckets, $bucket, true);
    }
}
