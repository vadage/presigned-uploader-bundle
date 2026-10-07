<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vadage\PresignedUploaderBundle\Event\PreSignEvent;
use Vadage\PresignedUploaderBundle\Event\UploadClaimedEvent;
use Vadage\PresignedUploaderBundle\Exception\UploadDeniedException;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Exception\UploadValidationException;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Naming\NamerInterface;
use Vadage\PresignedUploaderBundle\Naming\UploadContext;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Staging\CopyPromoter;
use Vadage\PresignedUploaderBundle\Staging\PromoterInterface;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

final readonly class UploadManager
{
    /**
     * @param ContainerInterface $namers    service id => NamerInterface
     * @param ContainerInterface $promoters service id => PromoterInterface
     */
    public function __construct(
        private MappingRegistry $mappings,
        private StorageRegistry $storages,
        private PendingUploadRepositoryInterface $repository,
        private ValidatorInterface $validator,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
        private ContainerInterface $namers,
        private ContainerInterface $promoters,
        private UploadVerifier $verifier,
        private ObjectDeleter $deleter,
        #[\SensitiveParameter]
        private string $secret,
        private int $claimTtl,
        private LoggerInterface $logger = new NullLogger(),
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * @throws UploadValidationException
     * @throws UploadDeniedException
     */
    public function presign(string $mappingName, UploadDescriptor $descriptor, string $ownerId): PresignResult
    {
        $mapping = $this->mappings->get($mappingName);

        $violations = $this->validateDescriptor($mapping, $descriptor);
        $violations->addAll($this->validator->validatePropertyValue($mapping->class, $mapping->property, $descriptor, [PresignedFile::PRESIGN_GROUP]));
        if (\count($violations) > 0) {
            throw new UploadValidationException($violations);
        }

        $event = new PreSignEvent($mapping, $descriptor, $ownerId);
        $this->dispatcher->dispatch($event);
        if (null !== $reason = $event->getDenyReason()) {
            throw new UploadDeniedException($reason, $event->getDenyStatusCode(), $event->getDenyHeaders());
        }

        $namer = $this->namers->get($mapping->namer);
        \assert($namer instanceof NamerInterface);
        $targetKey = $namer->name(new UploadContext($mapping, $descriptor, $ownerId));
        $storage = $mapping->staging->storage ?? $mapping->storage;
        $key = ($mapping->staging->prefix ?? '').$targetKey;

        $now = $this->clock->now();
        $putExpiresAt = $now->modify(\sprintf('+%d seconds', $mapping->uploadTtl));
        $upload = new PendingUpload(
            Uuid::v7(),
            $mapping->name,
            $ownerId,
            $storage,
            $key,
            $mapping->storage,
            $targetKey,
            $descriptor->filename,
            $descriptor->size,
            $descriptor->mimeType,
            $mapping->checksum ? $descriptor->sha256 : null,
            $now,
            $putExpiresAt,
            $now->modify(\sprintf('+%d seconds', $this->claimTtl)),
        );
        $this->repository->add($upload);

        $request = $this->storages->uploads($storage)->presignPut(
            $key,
            $descriptor->size,
            $descriptor->mimeType,
            $upload->getSha256(),
            $putExpiresAt,
        );

        return new PresignResult($upload, $this->token($upload->getId()), $request);
    }

    public function findByToken(string $token): ?PendingUpload
    {
        $parts = explode('.', $token, 2);
        if (2 !== \count($parts) || !Uuid::isValid($parts[0], Uuid::FORMAT_BASE_58)) {
            return null;
        }

        $id = Uuid::fromBase58($parts[0]);
        if (!hash_equals($this->token($id), $token)) {
            return null;
        }

        return $this->repository->find($id);
    }

    /**
     * Resolves a token submitted by the client into a StoredObject, without claiming it yet.
     * Verifies the upload first if neither the client nor a storage event did.
     *
     * @param string|null $mappingName null accepts uploads for any mapping; claim() then has to check the mapping of
     *                                 the property the reference is stored in (the Doctrine flush listener does)
     *
     * @throws UploadNotClaimableException
     */
    public function resolveClaimable(string $token, ?string $mappingName, string $ownerId): StoredObject
    {
        $upload = $this->findByToken($token);
        if (null === $upload || null !== $mappingName && $upload->getMapping() !== $mappingName || $upload->getOwnerId() !== $ownerId) {
            throw new UploadNotClaimableException('The upload does not exist.');
        }
        if ($upload->getExpiresAt() <= $this->clock->now()) {
            throw new UploadNotClaimableException('The upload has expired.');
        }

        $result = $this->verifier->verify($upload);
        if (UploadState::Verified !== $result->state) {
            throw new UploadNotClaimableException(match ($result->state) {
                UploadState::Pending => 'The file has not been uploaded yet.', UploadState::Rejected => 'The file did not pass verification.', default => \sprintf('The upload is "%s", only verified uploads can be claimed.', $result->state->value),
            }, \count($result->violations) > 0 ? $result->violations : null);
        }

        return StoredObject::fromClaimableUpload($upload);
    }

    /**
     * Starts claiming the upload behind a StoredObject from resolveClaimable(), and promotes it from staging.
     *
     * Call it before storing the reference, and commitClaim() once the reference is stored, or abandonClaim()
     * if storing it failed. With Doctrine, the bundle's flush listener does all of this. A claim that is
     * neither committed nor abandoned is reverted by the cleanup command after the claim lease.
     *
     * @param string|null $mappingName the mapping of the property the reference is stored in; required when the object
     *                                 does not come from resolveClaimable() with that mapping, e.g. from the serializer
     *
     * @return Claim|null null for objects that do not come from an upload (e.g. loaded from the database)
     *
     * @throws UploadNotClaimableException
     */
    public function claim(StoredObject $object, ?string $mappingName = null): ?Claim
    {
        if (null === $id = $object->getPendingUploadId()) {
            return null;
        }
        if (null !== $mappingName && $object->getPendingMapping() !== $mappingName) {
            throw new UploadNotClaimableException(\sprintf('The upload was made for "%s", it cannot be stored as "%s".', $object->getPendingMapping(), $mappingName));
        }

        $upload = $this->repository->find(Uuid::fromString($id));
        if (null === $upload || !$this->repository->transition($upload, [UploadState::Verified], UploadState::Claiming, $this->clock->now())) {
            throw new UploadNotClaimableException('The upload is no longer available.');
        }

        $mapping = $this->mappings->get($upload->getMapping());
        if ($upload->isStaged()) {
            // Staged when it was presigned, even if the mapping has no staging anymore.
            $promoter = null !== $mapping->staging ? $this->promoters->get($mapping->staging->promoter) : new CopyPromoter();
            \assert($promoter instanceof PromoterInterface);
            $promoter->promote($upload, $this->storages->objects($upload->getStorage()), $this->storages->objects($upload->getTargetStorage()));
        }

        return new Claim($upload, $mapping, $object);
    }

    /**
     * Ends a claim after the reference has been stored: removes the upload record and the staging object.
     *
     * Only for claims made outside of a Doctrine flush, the flush listener commits its own claims
     * in the same transaction as the reference.
     */
    public function commitClaim(Claim $claim): void
    {
        // First: once the record is gone, nothing reverts or expires the claimed object anymore.
        $this->repository->remove($claim->upload);
        $claim->object->markClaimed();

        if (null !== $tombstone = $this->deleter->stagingTombstone($claim->upload)) {
            try {
                $this->deleter->executeUnsaved($tombstone);
            } catch (\Throwable $e) {
                // The claim is committed; the cleanup command deletes the staging object later.
                $this->logger->warning('Could not delete staging object {key}: {error}', ['key' => $tombstone->getKey(), 'error' => $e->getMessage(), 'exception' => $e]);
            }
        }

        $this->dispatcher->dispatch(new UploadClaimedEvent($claim->upload, $claim->mapping, $claim->object));
    }

    /**
     * Reverts a claim whose reference could not be stored, so the upload can be claimed again right away
     * instead of after the claim lease.
     */
    public function abandonClaim(Claim $claim): void
    {
        // Any claim of this upload started before now, i.e. this one.
        $this->repository->revertStaleClaim($claim->upload, $this->clock->now()->modify('+1 second'));
    }

    /**
     * Deletes a stored object now, and again later if its presigned PUT could still recreate it.
     * Call it after the transaction that dropped the reference committed.
     */
    public function delete(StoredObject $object): void
    {
        $this->deleter->executeUnsaved($this->deleter->tombstoneFor($object));
    }

    /**
     * Tokens are signed so upload ids cannot be guessed or enumerated.
     */
    private function token(Uuid $id): string
    {
        $mac = hash_hmac('sha256', 'vadage_presigned_upload|'.$id->toRfc4122(), $this->secret, true);

        return $id->toBase58().'.'.rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
    }

    private function validateDescriptor(UploadMapping $mapping, UploadDescriptor $descriptor): ConstraintViolationList
    {
        $violations = new ConstraintViolationList();
        $add = function (string $path, string $message, mixed $value) use ($violations, $descriptor): void {
            $violations->add(new ConstraintViolation($this->translator?->trans($message, [], 'validators') ?? $message, $message, [], $descriptor, $path, $value));
        };

        if ('' === trim($descriptor->filename) || mb_strlen($descriptor->filename) > 255 || 1 === preg_match('/[\x00-\x1F\x7F]/', $descriptor->filename)) {
            $add('filename', 'The file name is invalid.', $descriptor->filename);
        }
        if ($descriptor->size < 0) {
            $add('size', 'The file size is invalid.', $descriptor->size);
        } elseif ($descriptor->size > min($mapping->maxSize ?? VadagePresignedUploaderBundle::MAX_UPLOAD_SIZE, VadagePresignedUploaderBundle::MAX_UPLOAD_SIZE)) {
            $add('size', 'The file is too large.', $descriptor->size);
        }
        if (1 !== preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~', $descriptor->mimeType)) {
            $add('mimeType', 'The mime type is invalid.', $descriptor->mimeType);
        }
        if ($mapping->checksum && 32 !== \strlen((string) base64_decode((string) $descriptor->sha256, true))) {
            $add('sha256', 'A base64 encoded SHA-256 checksum is required.', $descriptor->sha256);
        }

        return $violations;
    }
}
