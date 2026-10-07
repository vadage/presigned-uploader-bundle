<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;
use Vadage\PresignedUploaderBundle\Event\UploadClaimedEvent;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Upload\Claim;
use Vadage\PresignedUploaderBundle\Upload\ObjectDeleter;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * Ties storage side effects to the transaction that stores the references:
 *
 * - New uploads are claimed (and promoted) before the flush; their records are deleted in the flush
 *   transaction. Claims of a flush that fails are abandoned, so the uploads can be claimed again.
 * - Replaced and removed objects become tombstones written in the flush transaction. They are deleted
 *   right after the flush when it committed, or by the cleanup command when an outer transaction is still
 *   open; a rollback drops the tombstones and keeps the objects.
 *
 * Network calls (promotion) happen inside the flush, keep that in mind for large staged files.
 *
 * @internal
 */
final class UploadLifecycleListener implements ResetInterface
{
    /** @var \WeakMap<EntityManagerInterface, array{claims: list<Claim>, deferred: list<Claim>, tombstones: list<ObjectTombstone>, unsaved: list<ObjectTombstone>}> */
    private \WeakMap $flushes;

    public function __construct(
        private readonly MappingRegistry $mappings,
        private readonly UploadManager $manager,
        private readonly ObjectDeleter $deleter,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->flushes = new \WeakMap();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        // A previous flush that failed after onFlush never reached postFlush.
        $this->abandon($em);

        $changed = [...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()];
        $this->assertUploadsAreMapped($em, $changed);

        $flush = ['claims' => [], 'deferred' => [], 'tombstones' => [], 'unsaved' => []];
        try {
            $this->collect($em, $changed, $flush);
        } finally {
            // Also when claiming failed half way: postFlush or abandon() takes it from here.
            $this->flushes[$em] = $flush;
        }
    }

    /**
     * @param list<object>                                                                                                         $changed
     * @param array{claims: list<Claim>, deferred: list<Claim>, tombstones: list<ObjectTombstone>, unsaved: list<ObjectTombstone>} $flush
     */
    private function collect(EntityManagerInterface $em, array $changed, array &$flush): void
    {
        $uow = $em->getUnitOfWork();
        $stored = $obsolete = [];

        foreach ($changed as $entity) {
            $metadata = $em->getClassMetadata($entity::class);
            $changeSet = $uow->getEntityChangeSet($entity);
            foreach ($this->mappings->forClass($metadata->getName()) as $property => $mapping) {
                $value = $this->value($metadata, $entity, $property);
                if ($value instanceof StoredObject) {
                    $stored[self::location($value)] = true;
                }
                // Insertions list every field; unchanged values were claimed by the flush that stored them.
                if (!\array_key_exists($property, $changeSet)) {
                    continue;
                }

                if ($value instanceof StoredObject && null !== $claim = $this->manager->claim($value, $mapping->name)) {
                    if ($em->contains($claim->upload)) {
                        // Deleted in the same transaction that stores the reference.
                        $em->remove($claim->upload);
                        if (null !== $tombstone = $this->deleter->stagingTombstone($claim->upload)) {
                            $flush['tombstones'][] = $tombstone;
                        }
                        $flush['claims'][] = $claim;
                    } else {
                        $flush['deferred'][] = $claim;
                    }
                }

                $old = $changeSet[$property][0] ?? null;
                if ($mapping->deleteOnReplace && $old instanceof StoredObject && (!$value instanceof StoredObject || !$value->isSameObject($old))) {
                    $obsolete[] = $old;
                }
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $metadata = $em->getClassMetadata($entity::class);
            $original = $uow->getOriginalEntityData($entity);
            foreach ($this->mappings->forClass($metadata->getName()) as $property => $mapping) {
                $value = \array_key_exists($property, $original) ? $original[$property] : $this->value($metadata, $entity, $property);
                if ($mapping->deleteOnRemove && $value instanceof StoredObject) {
                    $obsolete[] = $value;
                }
            }
        }

        foreach ($obsolete as $object) {
            // Still referenced when it moved to another property or entity in this flush.
            if (!isset($stored[self::location($object)])) {
                $flush['tombstones'][] = $this->deleter->tombstoneFor($object);
            }
        }

        if ($em->getMetadataFactory()->isTransient(ObjectTombstone::class)) {
            // The bundle's entities live in another entity manager: no transactional guarantee.
            $flush['unsaved'] = $flush['tombstones'];
            $flush['tombstones'] = [];
        } else {
            $tombstoneMetadata = $em->getClassMetadata(ObjectTombstone::class);
            foreach ($flush['tombstones'] as $tombstone) {
                $em->persist($tombstone);
                $uow->computeChangeSet($tombstoneMetadata, $tombstone);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $flush = $this->flushes[$em] ?? null;
        unset($this->flushes[$em]);
        if (null === $flush) {
            return;
        }

        // Inside an outer transaction, the flush only released a savepoint: nothing is committed yet.
        $committed = !$em->getConnection()->isTransactionActive();

        foreach ($flush['claims'] as $claim) {
            if ($committed) {
                // Inside an outer transaction, keep it: if that rolls back, flushing the object again claims it again.
                $claim->object->markClaimed();
            }
            $this->safely(fn (): object => $this->dispatcher->dispatch(new UploadClaimedEvent($claim->upload, $claim->mapping, $claim->object)), 'A listener failed for the claim of {key}', $claim->upload->getTargetKey());
        }
        foreach ($flush['deferred'] as $claim) {
            if (!$committed) {
                $this->logger->warning('Committing the claim of {key} before the surrounding transaction: the upload records are stored with another entity manager. A rollback leaves the object orphaned.', ['key' => $claim->upload->getTargetKey()]);
            }
            try {
                $this->manager->commitClaim($claim);
            } catch (\Throwable $e) {
                // The reference is stored, but the record still says "claiming": the cleanup command would revert
                // the claim and delete the object once it expires.
                $this->logger->critical('Could not commit the claim of {key}, which is referenced now. Call UploadManager::commitClaim() for upload {id} before its claim lease runs out, or the object will be deleted when the upload expires: {error}', [
                    'key' => $claim->upload->getTargetKey(),
                    'id' => $claim->upload->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if (!$committed) {
            // The cleanup command deletes the stored tombstones once the transaction committed.
            foreach ($flush['unsaved'] as $tombstone) {
                $this->logger->warning('Not deleting {key} inside an open transaction: the upload records are stored with another entity manager. The object stays orphaned.', ['key' => $tombstone->getKey()]);
            }

            return;
        }

        foreach ($flush['tombstones'] as $tombstone) {
            $this->safely(fn () => $this->deleter->execute($tombstone), 'Could not delete {key}, the cleanup command retries', $tombstone->getKey());
        }
        foreach ($flush['unsaved'] as $tombstone) {
            $this->safely(fn () => $this->deleter->executeUnsaved($tombstone), 'Could not delete obsolete object {key}', $tombstone->getKey());
        }
    }

    /**
     * Abandons the claims of flushes that failed (called at the end of a request, and between messages in workers).
     */
    public function reset(): void
    {
        foreach ($this->flushes as $em => $flush) {
            $this->abandon($em);
        }
    }

    private function abandon(EntityManagerInterface $em): void
    {
        $flush = $this->flushes[$em] ?? null;
        unset($this->flushes[$em]);
        foreach ([...$flush['claims'] ?? [], ...$flush['deferred'] ?? []] as $claim) {
            $this->safely(fn () => $this->manager->abandonClaim($claim), 'Could not abandon the claim of {key}, it is reverted after the claim lease', $claim->upload->getTargetKey());
        }
    }

    /**
     * An upload stored where the listener does not look would never be claimed, and expire later.
     *
     * @param list<object> $entities
     */
    private function assertUploadsAreMapped(EntityManagerInterface $em, array $entities): void
    {
        $uow = $em->getUnitOfWork();
        foreach ($entities as $entity) {
            $class = $em->getClassMetadata($entity::class)->getName();
            $mappings = $this->mappings->forClass($class);
            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                // Collections appear as PersistentCollection, fields as [old, new].
                $value = \is_array($change) ? $change[1] : null;
                if ($value instanceof StoredObject && null !== $value->getPendingUploadId() && !isset($mappings[$field])) {
                    throw new \LogicException(\sprintf('%s::$%s holds an upload, but has no #[UploadableField] on a property of an #[Uploadable] entity (embeddables are not supported), so it would never be claimed.', $class, $field));
                }
            }
        }
    }

    private static function location(StoredObject $object): string
    {
        return $object->getStorage()."\0".$object->getKey();
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function value(ClassMetadata $metadata, object $entity, string $property): mixed
    {
        return $metadata->hasField($property) ? $metadata->getFieldValue($entity, $property) : null;
    }

    private function safely(callable $operation, string $message, string $key): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->logger->error($message.': {error}', ['key' => $key, 'error' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
