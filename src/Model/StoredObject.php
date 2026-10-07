<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Model;

use Vadage\PresignedUploaderBundle\Util\TypedArray;

/**
 * An object in a storage, as referenced by an #[UploadableField] property.
 *
 * Persisted with the "presigned_stored_object" Doctrine type.
 */
final class StoredObject
{
    /**
     * Set when the object comes from an upload that has to be claimed when the reference is stored.
     * Never persisted: an object loaded from the database has none.
     */
    private ?string $pendingUploadId = null;

    /** The mapping of that upload: the reference may only be stored in its property. */
    private ?string $pendingMapping = null;

    public function __construct(
        private readonly string $storage,
        private readonly string $key,
        private readonly int $size,
        private readonly string $mimeType,
        private readonly string $originalName,
        private readonly ?string $sha256,
        private readonly \DateTimeImmutable $uploadedAt,
    ) {
    }

    /**
     * @internal
     */
    public static function fromClaimableUpload(PendingUpload $upload): self
    {
        $object = new self(
            $upload->getTargetStorage(),
            $upload->getTargetKey(),
            $upload->getSize(),
            $upload->getMimeType(),
            $upload->getFilename(),
            $upload->getSha256(),
            $upload->getVerifiedAt() ?? throw new \LogicException('Only verified uploads can be claimed.'),
        );
        $object->pendingUploadId = $upload->getId()->toRfc4122();
        $object->pendingMapping = $upload->getMapping();

        return $object;
    }

    /**
     * Unknown keys are ignored. Fields added after the first release must be optional here,
     * so rows written by older versions keep loading without a data migration.
     *
     * @param array<mixed> $data as returned by toArray()
     */
    public static function fromArray(array $data): self
    {
        return new self(
            TypedArray::string($data, 'storage'),
            TypedArray::string($data, 'key'),
            TypedArray::int($data, 'size'),
            TypedArray::string($data, 'mimeType'),
            TypedArray::string($data, 'originalName'),
            TypedArray::nullableString($data, 'sha256'),
            new \DateTimeImmutable(TypedArray::string($data, 'uploadedAt')),
        );
    }

    /**
     * @return array{storage: string, key: string, size: int, mimeType: string, originalName: string, sha256: string|null, uploadedAt: string}
     */
    public function toArray(): array
    {
        return [
            'storage' => $this->storage,
            'key' => $this->key,
            'size' => $this->size,
            'mimeType' => $this->mimeType,
            'originalName' => $this->originalName,
            'sha256' => $this->sha256,
            'uploadedAt' => $this->uploadedAt->format(\DATE_ATOM),
        ];
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getUploadedAt(): \DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function isSameObject(self $other): bool
    {
        return $this->storage === $other->storage && $this->key === $other->key;
    }

    /**
     * @internal
     */
    public function getPendingUploadId(): ?string
    {
        return $this->pendingUploadId;
    }

    /**
     * @internal
     */
    public function getPendingMapping(): ?string
    {
        return $this->pendingMapping;
    }

    /**
     * Called once the claim is committed: the object can then be assigned elsewhere like any stored object.
     *
     * @internal
     */
    public function markClaimed(): void
    {
        $this->pendingUploadId = $this->pendingMapping = null;
    }
}
