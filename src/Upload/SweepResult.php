<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

final readonly class SweepResult
{
    /**
     * @param int          $revertedClaims claims that never committed, claimable again
     * @param int          $expiredUploads unclaimed uploads deleted after their deadline
     * @param int          $deletedObjects objects deleted for tombstones (again while a presigned PUT could recreate them)
     * @param list<string> $failures       storage operations to retry on the next run
     */
    public function __construct(
        public int $revertedClaims,
        public int $expiredUploads,
        public int $deletedObjects,
        public array $failures,
    ) {
    }
}
