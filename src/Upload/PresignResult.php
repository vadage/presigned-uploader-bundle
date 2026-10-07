<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Storage\PresignedRequest;

final readonly class PresignResult
{
    public function __construct(
        public PendingUpload $upload,
        public string $token,
        public PresignedRequest $request,
    ) {
    }
}
