<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Psr\Clock\ClockInterface;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;

/**
 * Deletes objects through tombstones: the object is deleted right away when possible, and the tombstone is
 * kept until no presigned PUT can recreate the object, so the cleanup command deletes it again until then.
 *
 * @internal
 */
final readonly class ObjectDeleter
{
    public function __construct(
        private MappingRegistry $mappings,
        private StorageRegistry $storages,
        private PendingUploadRepositoryInterface $repository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The staging object of a claimed upload, to delete once the claim is committed.
     */
    public function stagingTombstone(PendingUpload $upload): ?ObjectTombstone
    {
        return $upload->isStaged() ? ObjectTombstone::create($upload->getStorage(), $upload->getKey(), $upload->getPutExpiresAt()) : null;
    }

    /**
     * A stored object that is no longer referenced. Its presigned PUT expired at most "upload TTL" after it
     * was uploaded, the longest TTL of the mappings uploading to its storage.
     */
    public function tombstoneFor(StoredObject $object): ObjectTombstone
    {
        $putExpiresAt = $object->getUploadedAt()->modify(\sprintf('+%d seconds', $this->mappings->maxUploadTtl($object->getStorage())));

        return ObjectTombstone::create($object->getStorage(), $object->getKey(), max($this->clock->now(), $putExpiresAt));
    }

    /**
     * Deletes the object of a stored tombstone, and the tombstone once it is due.
     */
    public function execute(ObjectTombstone $tombstone): void
    {
        $this->storages->objects($tombstone->getStorage())->delete($tombstone->getKey());

        if ($tombstone->getNotBefore() <= $this->clock->now()) {
            $this->repository->removeTombstone($tombstone);
        }
    }

    /**
     * Deletes the object of a tombstone that has not been stored, storing it first when it is not due yet.
     */
    public function executeUnsaved(ObjectTombstone $tombstone): void
    {
        if ($tombstone->getNotBefore() > $this->clock->now()) {
            $this->repository->addTombstone($tombstone);
        }

        $this->storages->objects($tombstone->getStorage())->delete($tombstone->getKey());
    }
}
