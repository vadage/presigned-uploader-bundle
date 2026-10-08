<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\PresignedUpload;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\PresignedUploadViolation;

/**
 * Not through "api_platform.mapping.paths": any configured path turns off the application's default resource directories.
 *
 * @internal
 */
final readonly class PresignedUploadResourceNameCollectionFactory implements ResourceNameCollectionFactoryInterface
{
    public function __construct(
        private ResourceNameCollectionFactoryInterface $decorated,
    ) {
    }

    public function create(): ResourceNameCollection
    {
        $classes = iterator_to_array($this->decorated->create(), false);

        return new ResourceNameCollection(array_values(array_unique([...$classes, PresignedUpload::class, PresignedUploadViolation::class])));
    }
}
