<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Exception;

use Symfony\Component\Validator\ConstraintViolationListInterface;

final class UploadValidationException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly ConstraintViolationListInterface $violations)
    {
        parent::__construct('The upload is not valid: '.$violations);
    }
}
