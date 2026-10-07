<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Event;

use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;

/**
 * Dispatched after validation, before signing. Use it for authorization, quotas or rate limiting.
 */
final class PreSignEvent
{
    private ?string $denyReason = null;
    private int $denyStatusCode = 403;
    /** @var array<string, string> */
    private array $denyHeaders = [];

    public function __construct(
        public readonly UploadMapping $mapping,
        public readonly UploadDescriptor $descriptor,
        public readonly string $ownerId,
    ) {
    }

    /**
     * @param int                   $statusCode the HTTP status of the presign response, e.g. 429 for a rate limit
     * @param array<string, string> $headers    response headers, e.g. "Retry-After"
     */
    public function deny(string $reason = 'The upload is not allowed.', int $statusCode = 403, array $headers = []): void
    {
        $this->denyReason = $reason;
        $this->denyStatusCode = $statusCode;
        $this->denyHeaders = $headers;
    }

    public function getDenyReason(): ?string
    {
        return $this->denyReason;
    }

    public function getDenyStatusCode(): int
    {
        return $this->denyStatusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getDenyHeaders(): array
    {
        return $this->denyHeaders;
    }
}
