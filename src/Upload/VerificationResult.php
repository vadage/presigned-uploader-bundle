<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Vadage\PresignedUploaderBundle\Model\UploadState;

final readonly class VerificationResult
{
    public function __construct(
        public UploadState $state,
        public ConstraintViolationListInterface $violations = new ConstraintViolationList(),
    ) {
    }
}
