<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Form;

use Symfony\Component\Form\FormTypeGuesserInterface;
use Symfony\Component\Form\Guess\Guess;
use Symfony\Component\Form\Guess\TypeGuess;
use Symfony\Component\Form\Guess\ValueGuess;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;

/**
 * Makes $builder->add('avatar') pick PresignedUploadType for #[UploadableField] properties.
 */
final readonly class PresignedUploadTypeGuesser implements FormTypeGuesserInterface
{
    public function __construct(private MappingRegistry $mappings)
    {
    }

    public function guessType(string $class, string $property): ?TypeGuess
    {
        $mapping = $this->mappings->find($class, $property);

        return null === $mapping || !$mapping->uploads ? null : new TypeGuess(PresignedUploadType::class, ['mapping' => $mapping->name], Guess::VERY_HIGH_CONFIDENCE);
    }

    public function guessRequired(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    public function guessMaxLength(string $class, string $property): ?ValueGuess
    {
        return null;
    }

    public function guessPattern(string $class, string $property): ?ValueGuess
    {
        return null;
    }
}
