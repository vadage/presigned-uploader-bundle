<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiResource;
use Symfony\Component\Validator\ConstraintViolationListInterface;

#[ApiResource(operations: [], paginationEnabled: false, graphQlOperations: [])]
final readonly class PresignedUploadViolation
{
    public function __construct(
        public string $propertyPath,
        public string $message,
    ) {
    }

    /**
     * @return list<self>
     */
    public static function fromList(ConstraintViolationListInterface $violations): array
    {
        $result = [];
        foreach ($violations as $violation) {
            $result[] = new self($violation->getPropertyPath(), (string) $violation->getMessage());
        }

        return $result;
    }
}
