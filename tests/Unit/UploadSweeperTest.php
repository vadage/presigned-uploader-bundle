<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Uid\Uuid;
use Vadage\PresignedUploaderBundle\Command\CleanupCommand;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Storage\ObjectStoreInterface;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Tests\App\FailingObjectStore;
use Vadage\PresignedUploaderBundle\Tests\App\InMemoryPendingUploadRepository;
use Vadage\PresignedUploaderBundle\Upload\ObjectDeleter;
use Vadage\PresignedUploaderBundle\Upload\UploadSweeper;

final class UploadSweeperTest extends TestCase
{
    private InMemoryPendingUploadRepository $repository;
    private MockClock $clock;
    private FailingObjectStore $store;
    private UploadSweeper $sweeper;

    protected function setUp(): void
    {
        $this->repository = new InMemoryPendingUploadRepository();
        $this->clock = new MockClock('2026-10-07 12:00:00');
        $this->store = new FailingObjectStore();

        $storages = new StorageRegistry(new ServiceLocator([]), new ServiceLocator(['private' => fn (): ObjectStoreInterface => $this->store]), []);
        $deleter = new ObjectDeleter(new MappingRegistry([]), $storages, $this->repository, $this->clock);
        $this->sweeper = new UploadSweeper($this->repository, $storages, $deleter, $this->clock, 900);
    }

    public function testExpiredUploadIsKeptWhenItsObjectCannotBeDeleted(): void
    {
        $upload = $this->addExpiredUpload('a.txt');

        $result = $this->sweeper->sweep();

        self::assertSame(0, $result->expiredUploads);
        self::assertSame(['Could not delete "a.txt" from storage "private": down'], $result->failures);
        self::assertSame(UploadState::Expired, $this->repository->find($upload->getId())?->getState(), 'Cannot be claimed any more');

        $this->store->failing = false;
        $result = $this->sweeper->sweep();

        self::assertSame(1, $result->expiredUploads, 'Retried by the next run');
        self::assertSame([], $result->failures);
        self::assertSame(['a.txt'], $this->store->deleted);
        self::assertNull($this->repository->find($upload->getId()));
    }

    public function testTombstoneIsKeptWhenItsObjectCannotBeDeleted(): void
    {
        $this->repository->addTombstone(ObjectTombstone::create('private', 'old.txt', $this->clock->now()->modify('-1 minute')));

        $result = $this->sweeper->sweep();

        self::assertSame(0, $result->deletedObjects);
        self::assertSame(['Could not delete "old.txt" from storage "private": down'], $result->failures);
        self::assertCount(1, $this->repository->findTombstones(10));

        $this->store->failing = false;
        $result = $this->sweeper->sweep();

        self::assertSame(1, $result->deletedObjects, 'Retried by the next run');
        self::assertSame(['old.txt'], $this->store->deleted);
        self::assertCount(0, $this->repository->findTombstones(10));
    }

    public function testOneFailureDoesNotStopTheOtherDeletions(): void
    {
        $this->addExpiredUpload('a.txt');
        $this->addExpiredUpload('b.txt');
        $this->repository->addTombstone(ObjectTombstone::create('private', 'old.txt', $this->clock->now()));

        $result = $this->sweeper->sweep();

        self::assertCount(3, $result->failures, 'Every record was attempted');
    }

    public function testCleanupCommandReportsFailures(): void
    {
        $this->addExpiredUpload('a.txt');
        $application = new Application();
        $application->addCommand(new CleanupCommand($this->sweeper));
        $tester = new CommandTester($application->find('vadage:presigned-uploader:cleanup'));

        self::assertSame(Command::FAILURE, $tester->execute([]), 'Lets cron jobs and monitoring notice');
        self::assertStringContainsString('[WARNING] Could not delete "a.txt" from storage "private": down', $tester->getDisplay());

        $this->store->failing = false;
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 1 expired upload(s)', $tester->getDisplay());
    }

    private function addExpiredUpload(string $key): PendingUpload
    {
        $createdAt = $this->clock->now()->modify('-2 days');
        $upload = new PendingUpload(Uuid::v7(), 'document_file', 'user:alice', 'private', $key, 'private', $key, $key, 5, 'text/plain', null, $createdAt, $createdAt->modify('+5 minutes'), $createdAt->modify('+1 day'));
        $this->repository->add($upload);

        return $upload;
    }
}
