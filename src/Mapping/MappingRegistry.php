<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Mapping;

use Vadage\PresignedUploaderBundle\Exception\MappingNotFoundException;

final class MappingRegistry
{
    /** @var array<string, UploadMapping> */
    private array $byName = [];

    /** @var array<string, array<string, UploadMapping>> class => property => mapping */
    private array $byClass = [];

    /**
     * @param iterable<UploadMapping> $mappings
     */
    public function __construct(iterable $mappings)
    {
        foreach ($mappings as $mapping) {
            $this->byName[$mapping->name] = $mapping;
            $this->byClass[$mapping->class][$mapping->property] = $mapping;
        }
    }

    public function get(string $name): UploadMapping
    {
        return $this->byName[$name] ?? throw new MappingNotFoundException($name);
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    public function find(string $class, string $property): ?UploadMapping
    {
        return $this->forClass($class)[$property] ?? null;
    }

    /**
     * The longest time a presigned PUT into $storage stays valid, over all mappings uploading there directly.
     * An object in that storage can be recreated through its URL for at most this long after it was uploaded.
     */
    public function maxUploadTtl(string $storage): int
    {
        $ttl = 0;
        foreach ($this->byName as $mapping) {
            if (($mapping->staging->storage ?? $mapping->storage) === $storage) {
                $ttl = max($ttl, $mapping->uploadTtl);
            }
        }

        return $ttl;
    }

    /**
     * Includes mappings declared on parent classes.
     *
     * @return array<string, UploadMapping> property => mapping
     */
    public function forClass(string $class): array
    {
        $mappings = [];
        foreach ($this->byClass as $mappedClass => $classMappings) {
            if (is_a($class, $mappedClass, true)) {
                $mappings += $classMappings;
            }
        }

        return $mappings;
    }
}
