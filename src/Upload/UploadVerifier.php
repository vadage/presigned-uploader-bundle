<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vadage\PresignedUploaderBundle\Event\UploadRejectedEvent;
use Vadage\PresignedUploaderBundle\Event\UploadVerifiedEvent;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Storage\ObjectMetadata;
use Vadage\PresignedUploaderBundle\Storage\S3Storage;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

/**
 * Checks an uploaded object against what was signed and validated: size, content type, checksum, and the
 * "presign" constraints with the MIME type sniffed from the stored bytes.
 *
 * Runs when an upload is claimed, and earlier when the client calls the verify endpoint or a storage event
 * arrives. Whichever comes first wins; later calls return the current state.
 */
final readonly class UploadVerifier
{
    private const SNIFF_BYTES = 4096;

    public function __construct(
        private MappingRegistry $mappings,
        private StorageRegistry $storages,
        private PendingUploadRepositoryInterface $repository,
        private ValidatorInterface $validator,
        private EventDispatcherInterface $dispatcher,
        private ClockInterface $clock,
        private LoggerInterface $logger = new NullLogger(),
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public function verify(PendingUpload $upload): VerificationResult
    {
        if (UploadState::Pending !== $upload->getState()) {
            return new VerificationResult($upload->getState());
        }

        $mapping = $this->mappings->get($upload->getMapping());
        $storage = $this->storages->uploads($upload->getStorage());

        $metadata = $storage->head($upload->getKey(), null !== $upload->getSha256());
        if (null === $metadata) {
            return new VerificationResult(UploadState::Pending);
        }

        $violations = $this->check($upload, $mapping, $storage, $metadata);
        if (\count($violations) > 0) {
            return $this->reject($upload, $mapping, $violations);
        }

        if (!$this->repository->transition($upload, [UploadState::Pending], UploadState::Verified, $this->clock->now())) {
            return new VerificationResult($upload->getState());
        }

        $this->dispatcher->dispatch(new UploadVerifiedEvent($upload, $mapping));

        return new VerificationResult(UploadState::Verified);
    }

    /**
     * Verifies the upload stored at a bucket and key, e.g. when the storage reports a new object.
     *
     * @return VerificationResult|null null when no upload was presigned for that location (e.g. a promoted copy)
     */
    public function verifyLocation(string $bucket, string $key): ?VerificationResult
    {
        $upload = $this->repository->findByLocation($this->storages->namesForBucket($bucket), $key);

        return null === $upload ? null : $this->verify($upload);
    }

    private function check(PendingUpload $upload, UploadMapping $mapping, S3Storage $storage, ObjectMetadata $metadata): ConstraintViolationListInterface
    {
        // Enforced by the signature already; guards against storages ignoring signed headers.
        $mismatch = match (true) {
            $metadata->size !== $upload->getSize() => 'The stored file size does not match the announced size.',
            null !== $metadata->mimeType && strtolower($metadata->mimeType) !== $upload->getMimeType() => 'The stored content type does not match the announced content type.',
            null !== $upload->getSha256() && null !== $metadata->sha256 && !hash_equals($upload->getSha256(), $metadata->sha256) => 'The stored checksum does not match the announced checksum.',
            default => null,
        };
        if (null !== $mismatch) {
            return new ConstraintViolationList([new ConstraintViolation($this->translator?->trans($mismatch, [], 'validators') ?? $mismatch, $mismatch, [], $upload, '', null)]);
        }

        $actual = $upload->getDescriptor();
        // A range request on an empty object fails, and there is nothing to sniff anyway.
        if ($mapping->sniffContent && $upload->getSize() > 0) {
            $sniffed = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($storage->readStart($upload->getKey(), self::SNIFF_BYTES));
            if (\is_string($sniffed)) {
                $actual = $actual->withMimeType($sniffed);
            }
        }

        return $this->validator->validatePropertyValue($mapping->class, $mapping->property, $actual, [PresignedFile::PRESIGN_GROUP]);
    }

    private function reject(PendingUpload $upload, UploadMapping $mapping, ConstraintViolationListInterface $violations): VerificationResult
    {
        if (!$this->repository->transition($upload, [UploadState::Pending], UploadState::Rejected, $this->clock->now())) {
            return new VerificationResult($upload->getState());
        }

        $this->logger->info('Rejected upload {id} ({mapping}): {violations}', [
            'id' => $upload->getId()->toRfc4122(),
            'mapping' => $mapping->name,
            'violations' => (string) $violations,
        ]);

        $this->storages->objects($upload->getStorage())->delete($upload->getKey());
        $this->dispatcher->dispatch(new UploadRejectedEvent($upload, $mapping, $violations));

        return new VerificationResult(UploadState::Rejected, $violations);
    }
}
