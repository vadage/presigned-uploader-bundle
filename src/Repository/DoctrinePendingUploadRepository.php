<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;

/**
 * Writes with plain inserts and DQL instead of flush(): atomic, safe within Doctrine listeners, and without
 * writing whatever else the application has pending.
 */
final readonly class DoctrinePendingUploadRepository implements PendingUploadRepositoryInterface
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function add(PendingUpload $upload): void
    {
        $this->insert(PendingUpload::class, $upload);
    }

    public function find(Uuid $id): ?PendingUpload
    {
        return $this->em(PendingUpload::class)->find(PendingUpload::class, $id);
    }

    public function findByLocation(array $storages, string $key): ?PendingUpload
    {
        if ([] === $storages) {
            return null;
        }

        return $this->em(PendingUpload::class)->getRepository(PendingUpload::class)->findOneBy([
            'locationHash' => array_map(static fn (string $storage): string => PendingUpload::locationHash($storage, $key), $storages),
        ]);
    }

    public function transition(PendingUpload $upload, array $from, UploadState $to, \DateTimeImmutable $at): bool
    {
        $qb = $this->em(PendingUpload::class)->createQueryBuilder()
            ->update(PendingUpload::class, 'u')
            ->set('u.state', ':to')
            ->where('u.id = :id')
            ->andWhere('u.state IN (:from)')
            ->setParameter('to', $to->value)
            ->setParameter('id', $upload->getId(), 'uuid')
            ->setParameter('from', array_map(static fn (UploadState $s): string => $s->value, $from));
        if (UploadState::Verified === $to) {
            $qb->set('u.verifiedAt', ':at')->setParameter('at', $at, 'datetime_immutable');
        } elseif (UploadState::Claiming === $to) {
            $qb->set('u.claimedAt', ':at')->setParameter('at', $at, 'datetime_immutable');
        }

        return $this->apply($upload, 1 === $qb->getQuery()->execute(), $to, $at);
    }

    public function findExpired(\DateTimeImmutable $now, int $limit): iterable
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->neq('state', UploadState::Claiming))
            ->andWhere(Criteria::expr()->lt('expiresAt', $now))
            ->orderBy(['expiresAt' => Order::Ascending])
            ->setMaxResults($limit);

        return $this->em(PendingUpload::class)->getRepository(PendingUpload::class)->matching($criteria);
    }

    public function findStaleClaims(\DateTimeImmutable $claimedBefore, int $limit): iterable
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('state', UploadState::Claiming))
            ->andWhere(Criteria::expr()->lt('claimedAt', $claimedBefore))
            ->orderBy(['claimedAt' => Order::Ascending])
            ->setMaxResults($limit);

        return $this->em(PendingUpload::class)->getRepository(PendingUpload::class)->matching($criteria);
    }

    public function revertStaleClaim(PendingUpload $upload, \DateTimeImmutable $claimedBefore): bool
    {
        $updated = $this->em(PendingUpload::class)->createQueryBuilder()
            ->update(PendingUpload::class, 'u')
            ->set('u.state', ':to')
            ->where('u.id = :id')
            ->andWhere('u.state = :from')
            ->andWhere('u.claimedAt < :before')
            ->setParameter('to', UploadState::Verified->value)
            ->setParameter('id', $upload->getId(), 'uuid')
            ->setParameter('from', UploadState::Claiming->value)
            ->setParameter('before', $claimedBefore, 'datetime_immutable')
            ->getQuery()
            ->execute();

        return $this->apply($upload, 1 === $updated, UploadState::Verified, $claimedBefore);
    }

    public function remove(PendingUpload $upload): void
    {
        $this->delete(PendingUpload::class, $upload, $upload->getId());
    }

    public function addTombstone(ObjectTombstone $tombstone): void
    {
        $this->insert(ObjectTombstone::class, $tombstone);
    }

    public function findTombstones(int $limit): iterable
    {
        $criteria = Criteria::create()
            ->orderBy(['notBefore' => Order::Ascending])
            ->setMaxResults($limit);

        return $this->em(ObjectTombstone::class)->getRepository(ObjectTombstone::class)->matching($criteria);
    }

    public function removeTombstone(ObjectTombstone $tombstone): void
    {
        $this->delete(ObjectTombstone::class, $tombstone, $tombstone->getId());
    }

    /**
     * A plain insert: flush() would also write whatever else the application has pending, and run its listeners.
     *
     * @param class-string $class
     */
    private function insert(string $class, object $entity): void
    {
        $em = $this->em($class);
        $metadata = $em->getClassMetadata($class);
        $data = $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            $value = $metadata->getFieldValue($entity, $field);
            $data[$metadata->getColumnName($field)] = $value instanceof \BackedEnum ? $value->value : $value;
            $types[] = $metadata->getTypeOfField($field) ?? 'string';
        }

        $em->getConnection()->insert($metadata->getTableName(), $data, $types);
    }

    /**
     * Mirrors a successful state change on the object, or refreshes it after a failed one.
     */
    private function apply(PendingUpload $upload, bool $updated, UploadState $to, \DateTimeImmutable $at): bool
    {
        $em = $this->em(PendingUpload::class);
        if (!$updated) {
            try {
                $em->refresh($upload);
            } catch (EntityNotFoundException) {
                // Removed in the meantime.
            }

            return false;
        }

        $upload->applyState($to, $at);
        // Keep the unit of work in sync, so the next flush() does not write the change again.
        $uow = $em->getUnitOfWork();
        if ($uow->isInIdentityMap($upload)) {
            $oid = spl_object_id($upload);
            $uow->setOriginalEntityProperty($oid, 'state', $upload->getState());
            $uow->setOriginalEntityProperty($oid, 'verifiedAt', $upload->getVerifiedAt());
            $uow->setOriginalEntityProperty($oid, 'claimedAt', $upload->getClaimedAt());
        }

        return true;
    }

    /**
     * @param class-string $class
     */
    private function delete(string $class, object $entity, Uuid $id): void
    {
        $em = $this->em($class);
        $em->createQueryBuilder()
            ->delete($class, 'e')
            ->where('e.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->execute();

        if ($em->contains($entity)) {
            $em->detach($entity);
        }
    }

    /**
     * @param class-string $class
     */
    private function em(string $class): EntityManagerInterface
    {
        $em = $this->registry->getManagerForClass($class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException(\sprintf('No Doctrine ORM entity manager manages "%s".', $class));
        }

        return $em;
    }
}
