<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks an upload from presigning until it is claimed, rejected or expired.
 *
 * "storage"/"key" is where the browser uploads to (the staging location when staging is enabled),
 * "targetStorage"/"targetKey" is where the object lives once claimed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vadage_presigned_upload')]
#[ORM\UniqueConstraint(name: 'vadage_presigned_upload_location', columns: ['location_hash'])]
#[ORM\Index(name: 'vadage_presigned_upload_expiry', columns: ['state', 'expires_at'])]
#[ORM\Index(name: 'vadage_presigned_upload_claim', columns: ['state', 'claimed_at'])]
class PendingUpload
{
    #[ORM\Column(name: 'state', type: 'string', length: 16, enumType: UploadState::class)]
    private UploadState $state = UploadState::Pending;

    #[ORM\Column(name: 'verified_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'claimed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    /** Object keys are too long to index portably (MySQL limits index keys to 3072 bytes), their hash is not. */
    #[ORM\Column(name: 'location_hash', length: 64, options: ['fixed' => true])]
    private string $locationHash;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(name: 'mapping', length: 100)]
        private string $mapping,
        #[ORM\Column(name: 'owner_id', length: 255)]
        private string $ownerId,
        #[ORM\Column(name: 'storage', length: 100)]
        private string $storage,
        #[ORM\Column(name: 'object_key', length: 1024)]
        private string $key,
        #[ORM\Column(name: 'target_storage', length: 100)]
        private string $targetStorage,
        #[ORM\Column(name: 'target_key', length: 1024)]
        private string $targetKey,
        #[ORM\Column(name: 'filename', length: 255)]
        private string $filename,
        #[ORM\Column(name: 'size', type: 'bigint')]
        private int|string $size,
        #[ORM\Column(name: 'mime_type', length: 255)]
        private string $mimeType,
        #[ORM\Column(name: 'sha256', length: 64, nullable: true)]
        private ?string $sha256,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
        /** Until this point in time, the presigned PUT can (re)create the object at "storage"/"key". */
        #[ORM\Column(name: 'put_expires_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $putExpiresAt,
        /** After this point in time, the upload can no longer be claimed and gets cleaned up. */
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $expiresAt,
    ) {
        $this->locationHash = self::locationHash($storage, $key);
    }

    public static function locationHash(string $storage, string $key): string
    {
        return hash('sha256', $storage."\0".$key);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMapping(): string
    {
        return $this->mapping;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getTargetStorage(): string
    {
        return $this->targetStorage;
    }

    public function getTargetKey(): string
    {
        return $this->targetKey;
    }

    public function isStaged(): bool
    {
        return $this->storage !== $this->targetStorage || $this->key !== $this->targetKey;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getSize(): int
    {
        return (int) $this->size;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getDescriptor(): UploadDescriptor
    {
        return new UploadDescriptor($this->filename, $this->getSize(), $this->mimeType, $this->sha256);
    }

    public function getState(): UploadState
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPutExpiresAt(): \DateTimeImmutable
    {
        return $this->putExpiresAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getClaimedAt(): ?\DateTimeImmutable
    {
        return $this->claimedAt;
    }

    /**
     * Only to be called by a PendingUploadRepositoryInterface after a successful atomic transition.
     *
     * @internal
     */
    public function applyState(UploadState $state, \DateTimeImmutable $at): void
    {
        $this->state = $state;
        if (UploadState::Verified === $state) {
            // A reverted claim keeps the original completion time.
            $this->verifiedAt ??= $at;
        } elseif (UploadState::Claiming === $state) {
            $this->claimedAt = $at;
        }
    }
}
