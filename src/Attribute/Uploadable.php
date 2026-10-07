<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Attribute;

/**
 * Marks a class (entity or DTO) that holds #[UploadableField] properties.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Uploadable
{
}
