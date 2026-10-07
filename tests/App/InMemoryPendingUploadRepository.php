<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App;

use Symfony\Component\Uid\Uuid;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;

final class InMemoryPendingUploadRepository implements PendingUploadRepositoryInterface
{
    /** @var array<string, PendingUpload> */
    private array $uploads = [];

    /** @var array<string, ObjectTombstone> */
    private array $tombstones = [];

    public function add(PendingUpload $upload): void
    {
        $this->uploads[$upload->getId()->toRfc4122()] = $upload;
    }

    public function find(Uuid $id): ?PendingUpload
    {
        return $this->uploads[$id->toRfc4122()] ?? null;
    }

    public function findByLocation(array $storages, string $key): ?PendingUpload
    {
        foreach ($this->uploads as $upload) {
            if ($upload->getKey() === $key && \in_array($upload->getStorage(), $storages, true)) {
                return $upload;
            }
        }

        return null;
    }

    public function transition(PendingUpload $upload, array $from, UploadState $to, \DateTimeImmutable $at): bool
    {
        if (!\in_array($upload->getState(), $from, true)) {
            return false;
        }
        $upload->applyState($to, $at);

        return true;
    }

    public function findExpired(\DateTimeImmutable $now, int $limit): iterable
    {
        return $this->filter($this->uploads, static fn (PendingUpload $u): bool => UploadState::Claiming !== $u->getState() && $u->getExpiresAt() < $now, $limit);
    }

    public function findStaleClaims(\DateTimeImmutable $claimedBefore, int $limit): iterable
    {
        return $this->filter($this->uploads, static fn (PendingUpload $u): bool => UploadState::Claiming === $u->getState() && $u->getClaimedAt() < $claimedBefore, $limit);
    }

    public function revertStaleClaim(PendingUpload $upload, \DateTimeImmutable $claimedBefore): bool
    {
        if (UploadState::Claiming !== $upload->getState() || $upload->getClaimedAt() >= $claimedBefore) {
            return false;
        }
        $upload->applyState(UploadState::Verified, $claimedBefore);

        return true;
    }

    public function remove(PendingUpload $upload): void
    {
        unset($this->uploads[$upload->getId()->toRfc4122()]);
    }

    public function addTombstone(ObjectTombstone $tombstone): void
    {
        $this->tombstones[$tombstone->getId()->toRfc4122()] = $tombstone;
    }

    public function findTombstones(int $limit): iterable
    {
        $tombstones = array_values($this->tombstones);
        usort($tombstones, static fn (ObjectTombstone $a, ObjectTombstone $b): int => $a->getNotBefore() <=> $b->getNotBefore());

        return \array_slice($tombstones, 0, $limit);
    }

    public function removeTombstone(ObjectTombstone $tombstone): void
    {
        unset($this->tombstones[$tombstone->getId()->toRfc4122()]);
    }

    /**
     * @template T
     *
     * @param array<string, T>  $items
     * @param callable(T): bool $matches
     *
     * @return list<T>
     */
    private function filter(array $items, callable $matches, int $limit): array
    {
        return \array_slice(array_values(array_filter($items, $matches)), 0, $limit);
    }
}
