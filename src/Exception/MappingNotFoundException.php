<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Exception;

final class MappingNotFoundException extends \InvalidArgumentException implements ExceptionInterface
{
    public function __construct(public readonly string $mapping)
    {
        parent::__construct(\sprintf('No upload mapping named "%s" exists.', $mapping));
    }
}
