<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vadage\PresignedUploaderBundle\Upload\UploadSweeper;

#[AsCommand(
    name: 'vadage:presigned-uploader:cleanup',
    description: 'Deletes unclaimed uploads and obsolete objects, and reverts claims that never committed',
    help: 'Run it regularly, e.g. every few minutes with the Scheduler component or a cron job.',
)]
final readonly class CleanupCommand
{
    public function __construct(private UploadSweeper $sweeper)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Maximum number of records to process per step')] int $limit = 500): int
    {
        $result = $this->sweeper->sweep($limit);

        foreach ($result->failures as $failure) {
            $io->warning($failure);
        }
        $io->success(\sprintf(
            'Deleted %d expired upload(s) and %d obsolete object(s), reverted %d stale claim(s).',
            $result->expiredUploads,
            $result->deletedObjects,
            $result->revertedClaims,
        ));

        // Lets cron jobs and monitoring notice storage problems; the next run retries.
        return [] === $result->failures ? Command::SUCCESS : Command::FAILURE;
    }
}
