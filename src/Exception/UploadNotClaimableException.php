<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class UploadNotClaimableException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param ConstraintViolationListInterface|null $violations why verifying the upload failed, if it did
     */
    public function __construct(string $message, public readonly ?ConstraintViolationListInterface $violations = null)
    {
        parent::__construct($message);
    }
}
