<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Serializer;

use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * Writes a StoredObject from an upload id, the only input a client can be trusted with, and reads it with a URL.
 *
 * Without it, the ObjectNormalizer would build a StoredObject from any storage and key the client sends.
 * The upload is resolved for its own mapping; claiming it checks that it is stored in that mapping's property.
 */
final readonly class StoredObjectNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function __construct(
        private UploadManager $manager,
        private OwnerResolverInterface $ownerResolver,
        private StorageRegistry $storages,
    ) {
    }

    /**
     * @return array{url: string, size: int, mimeType: string, originalName: string, sha256: string|null, uploadedAt: string}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert($data instanceof StoredObject);

        return [
            'url' => $this->storages->objects($data->getStorage())->url($data->getKey()),
            'size' => $data->getSize(),
            'mimeType' => $data->getMimeType(),
            'originalName' => $data->getOriginalName(),
            'sha256' => $data->getSha256(),
            'uploadedAt' => $data->getUploadedAt()->format(\DATE_ATOM),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof StoredObject;
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $path = \is_string($context['deserialization_path'] ?? null) ? $context['deserialization_path'] : null;
        if (!\is_string($data) || '' === $data) {
            throw NotNormalizableValueException::createForUnexpectedDataType('Expected the id of an upload.', $data, ['string'], $path, true);
        }

        try {
            return $this->manager->resolveClaimable($data, null, $this->ownerResolver->resolve());
        } catch (UploadNotClaimableException $e) {
            $reasons = [];
            foreach ($e->violations ?? [] as $violation) {
                $reasons[] = (string) $violation->getMessage();
            }

            throw NotNormalizableValueException::createForUnexpectedDataType([] !== $reasons ? implode(' ', $reasons) : $e->getMessage(), $data, ['string'], $path, true, 0, $e);
        }
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return StoredObject::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [StoredObject::class => true];
    }
}
