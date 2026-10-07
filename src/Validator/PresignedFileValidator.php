<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;

final class PresignedFileValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PresignedFile) {
            throw new UnexpectedTypeException($constraint, PresignedFile::class);
        }

        if (null === $value) {
            return;
        }

        [$filename, $size, $mimeType] = match (true) {
            $value instanceof UploadDescriptor => [$value->filename, $value->size, $value->mimeType],
            $value instanceof StoredObject => [$value->getOriginalName(), $value->getSize(), $value->getMimeType()],
            default => throw new UnexpectedValueException($value, UploadDescriptor::class.'|'.StoredObject::class),
        };

        if (0 === $size) {
            $this->context->buildViolation($constraint->emptyMessage)
                ->setCode(PresignedFile::EMPTY_ERROR)
                ->addViolation();

            return;
        }

        if (null !== $constraint->maxSize && $size > $constraint->maxSize) {
            [$sizeAsString, $limitAsString, $suffix] = $this->formatSizes($size, $constraint->maxSize);
            $this->context->buildViolation($constraint->maxSizeMessage)
                ->setParameter('{{ size }}', $sizeAsString)
                ->setParameter('{{ limit }}', $limitAsString)
                ->setParameter('{{ suffix }}', $suffix)
                ->setCode(PresignedFile::TOO_LARGE_ERROR)
                ->addViolation();

            return;
        }

        if ([] !== $constraint->extensions) {
            $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
            if (!\in_array($extension, array_map(strtolower(...), $constraint->extensions), true)) {
                $this->context->buildViolation($constraint->extensionsMessage)
                    ->setParameter('{{ extension }}', $this->formatValue($extension))
                    ->setParameter('{{ extensions }}', $this->formatValues($constraint->extensions))
                    ->setCode(PresignedFile::INVALID_EXTENSION_ERROR)
                    ->addViolation();

                return;
            }
        }

        if ([] !== $constraint->mimeTypes && !self::mimeTypeMatches(strtolower($mimeType), $constraint->mimeTypes)) {
            $this->context->buildViolation($constraint->mimeTypesMessage)
                ->setParameter('{{ type }}', $this->formatValue($mimeType))
                ->setParameter('{{ types }}', $this->formatValues($constraint->mimeTypes))
                ->setCode(PresignedFile::INVALID_MIME_TYPE_ERROR)
                ->addViolation();
        }
    }

    /**
     * @param string[] $allowed
     */
    private static function mimeTypeMatches(string $mimeType, array $allowed): bool
    {
        foreach ($allowed as $candidate) {
            $candidate = strtolower($candidate);
            if ($candidate === $mimeType) {
                return true;
            }
            if (str_ends_with($candidate, '/*') && str_starts_with($mimeType, substr($candidate, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, string, string}
     */
    private function formatSizes(int $size, int $limit): array
    {
        foreach (['GB' => 1000 ** 3, 'MB' => 1000 ** 2, 'kB' => 1000] as $suffix => $factor) {
            if ($limit >= $factor) {
                return [(string) round($size / $factor, 2), (string) round($limit / $factor, 2), $suffix];
            }
        }

        return [(string) $size, (string) $limit, 'bytes'];
    }
}
