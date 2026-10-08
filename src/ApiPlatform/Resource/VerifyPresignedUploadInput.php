<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform\Resource;

final class VerifyPresignedUploadInput
{
    public function __construct(
        public string $uploadId,
    ) {
    }
}
