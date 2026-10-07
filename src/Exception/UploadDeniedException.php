<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Exception;

final class UploadDeniedException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        public readonly int $statusCode = 403,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
