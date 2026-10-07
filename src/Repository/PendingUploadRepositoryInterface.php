<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Repository;

use Symfony\Component\Uid\Uuid;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;

interface PendingUploadRepositoryInterface
{
    public function add(PendingUpload $upload): void;

    public function find(Uuid $id): ?PendingUpload;

    /**
     * Finds an upload by where the browser uploaded it to.
     *
     * @param list<string> $storages
     */
    public function findByLocation(array $storages, string $key): ?PendingUpload;

    /**
     * Atomically moves the upload to $to, if it currently is in one of the $from states.
     * Updates the given object on success, and refreshes it to the current state on failure.
     *
     * @param list<UploadState> $from
     *
     * @return bool false when another process changed the state first
     */
    public function transition(PendingUpload $upload, array $from, UploadState $to, \DateTimeImmutable $at): bool;

    /**
     * @return iterable<PendingUpload> uploads that are not being claimed and whose claim deadline passed
     */
    public function findExpired(\DateTimeImmutable $now, int $limit): iterable;

    /**
     * @return iterable<PendingUpload> claims that started before $claimedBefore and never committed
     */
    public function findStaleClaims(\DateTimeImmutable $claimedBefore, int $limit): iterable;

    /**
     * Atomically moves a claim that started before $claimedBefore back to Verified.
     *
     * Must not succeed while the transaction that claimed the upload is still running: with row locks
     * (Doctrine on MySQL, PostgreSQL), the update waits for it and finds the record deleted if it commits.
     *
     * @return bool false when the claim committed or was reverted in the meantime
     */
    public function revertStaleClaim(PendingUpload $upload, \DateTimeImmutable $claimedBefore): bool;

    public function remove(PendingUpload $upload): void;

    public function addTombstone(ObjectTombstone $tombstone): void;

    /**
     * @return iterable<ObjectTombstone> committed tombstones, the earliest "notBefore" first
     */
    public function findTombstones(int $limit): iterable;

    public function removeTombstone(ObjectTombstone $tombstone): void;
}
