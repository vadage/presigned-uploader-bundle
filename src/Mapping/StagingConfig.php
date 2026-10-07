<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Mapping;

final readonly class StagingConfig
{
    /**
     * @param string $storage  storage the browser uploads to
     * @param string $prefix   prepended to the final key to get the staging key
     * @param string $promoter service id of the PromoterInterface moving the object on claim
     */
    public function __construct(
        public string $storage,
        public string $prefix,
        public string $promoter,
    ) {
    }
}
