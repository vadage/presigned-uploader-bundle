<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

final readonly class PresignedRequest
{
    /**
     * @param array<string, string> $headers headers the client has to send along, they are part of the signature
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
