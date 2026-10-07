<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Psr\Clock\ClockInterface;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;

/**
 * The periodic part of the upload lifecycle, run by the cleanup command:
 *
 * 1. reverts claims whose transaction never committed (after the claim lease),
 * 2. deletes uploads that were not claimed before their deadline,
 * 3. deletes the objects behind committed tombstones (replaced, removed or staging objects), and the
 *    tombstones once no presigned PUT can recreate their object.
 */
final readonly class UploadSweeper
{
    public function __construct(
        private PendingUploadRepositoryInterface $repository,
        private StorageRegistry $storages,
        private ObjectDeleter $deleter,
        private ClockInterface $clock,
        private int $claimLease,
    ) {
    }

    public function sweep(int $limit = 500): SweepResult
    {
        $now = $this->clock->now();
        $failures = [];

        $reverted = 0;
        $claimedBefore = $now->modify(\sprintf('-%d seconds', $this->claimLease));
        foreach ($this->repository->findStaleClaims($claimedBefore, $limit) as $upload) {
            // A promoted copy stays at the target key: a new claim overwrites it, expiry deletes it.
            if ($this->repository->revertStaleClaim($upload, $claimedBefore)) {
                ++$reverted;
            }
        }

        $expired = 0;
        foreach ($this->repository->findExpired($now, $limit) as $upload) {
            $state = $upload->getState();
            // Atomic: a concurrent claim either wins (and we skip) or fails.
            if (UploadState::Expired !== $state && UploadState::Rejected !== $state
                && !$this->repository->transition($upload, [UploadState::Pending, UploadState::Verified], UploadState::Expired, $now)) {
                continue;
            }

            try {
                $this->storages->objects($upload->getStorage())->delete($upload->getKey());
                if ($upload->isStaged()) {
                    // Promoted by a claim that never committed. Never referenced: a committed claim deletes the record.
                    $this->storages->objects($upload->getTargetStorage())->delete($upload->getTargetKey());
                }
            } catch (\Throwable $e) {
                $failures[] = \sprintf('Could not delete "%s" from storage "%s": %s', $upload->getKey(), $upload->getStorage(), $e->getMessage());
                continue;
            }

            $this->repository->remove($upload);
            ++$expired;
        }

        $deleted = 0;
        foreach ($this->repository->findTombstones($limit) as $tombstone) {
            try {
                $this->deleter->execute($tombstone);
                ++$deleted;
            } catch (\Throwable $e) {
                $failures[] = \sprintf('Could not delete "%s" from storage "%s": %s', $tombstone->getKey(), $tombstone->getStorage(), $e->getMessage());
            }
        }

        return new SweepResult($reverted, $expired, $deleted, $failures);
    }
}
