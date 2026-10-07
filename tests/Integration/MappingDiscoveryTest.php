<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Tests\App\Dto\Attachment;
use Vadage\PresignedUploaderBundle\Tests\App\Dto\ContractAttachment;
use Vadage\PresignedUploaderBundle\Tests\App\Dto\InvoiceAttachment;
use Vadage\PresignedUploaderBundle\Tests\App\Dto\UnlimitedUpload;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;
use Vadage\PresignedUploaderBundle\Tests\App\InMemoryPendingUploadRepository;
use Vadage\PresignedUploaderBundle\Tests\App\TestKernel;

/**
 * Boots the container only, no storage needed.
 */
final class MappingDiscoveryTest extends TestCase
{
    public function testDiscoversUploadableClasses(): void
    {
        $container = $this->boot()->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $registry = $container->get(MappingRegistry::class);
        self::assertInstanceOf(MappingRegistry::class, $registry);

        $mapping = $registry->get('document_image');
        self::assertSame(Document::class, $mapping->class);
        self::assertSame('image', $mapping->property);
        self::assertSame('quarantine', $mapping->staging?->storage);
        self::assertTrue($mapping->checksum);
        self::assertNull($registry->get('document_file')->staging);
    }

    public function testMapsTheBundleEntitiesIntoTheDefaultOfNamedEntityManagers(): void
    {
        $kernel = new TestKernel('test', true, ['doctrine' => true, 'entity_managers' => true]);
        $kernel->boot();
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        self::assertSame(['main', 'other'], array_keys($doctrine->getManagerNames()));
        self::assertSame($doctrine->getManager('main'), $doctrine->getManagerForClass(PendingUpload::class));
        self::assertSame($doctrine->getManager('main'), $doctrine->getManagerForClass(ObjectTombstone::class));
    }

    public function testMapsTheBundleEntitiesIntoTheConfiguredEntityManager(): void
    {
        $kernel = new TestKernel('test', true, ['doctrine' => true, 'entity_managers' => true, 'config' => ['pending_upload' => ['entity_manager' => 'other']]]);
        $kernel->boot();
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        self::assertSame($doctrine->getManager('other'), $doctrine->getManagerForClass(PendingUpload::class));
    }

    public function testMapsAParentPropertyOnceForAllUploadableSubclasses(): void
    {
        $container = $this->boot(['mapped_classes' => [InvoiceAttachment::class, ContractAttachment::class]])->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $registry = $container->get(MappingRegistry::class);
        self::assertInstanceOf(MappingRegistry::class, $registry);

        self::assertSame(Attachment::class, $registry->get('attachment')->class);
        self::assertSame('attachment', $registry->find(InvoiceAttachment::class, 'file')?->name);
        self::assertSame('attachment', $registry->find(ContractAttachment::class, 'file')?->name);
    }

    public function testDoesNotMapTheBundleEntitiesForACustomRepository(): void
    {
        $kernel = new TestKernel('test', true, ['doctrine' => true, 'config' => ['pending_upload' => ['repository' => InMemoryPendingUploadRepository::class]]]);
        $kernel->boot();
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        self::assertNull($doctrine->getManagerForClass(PendingUpload::class));
    }

    public function testFailsOnUnknownStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage "private" is not configured');

        $this->boot(storages: ['public' => ['client' => 'test.s3_client', 'bucket' => 'b']]);
    }

    public function testFailsOnUploadsIntoAStorageWithoutClient(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage "archive" has no "client", it cannot receive uploads');

        $this->boot(['storages' => ['archive' => ['staging' => ['enabled' => false]]]]);
    }

    public function testFailsOnNamerNotImplementingTheInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('service "test.s3_client" does not exist or does not implement');

        $this->boot(['defaults' => ['namer' => 'test.s3_client']]);
    }

    public function testFailsOnMappingWithoutMaximumSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[UploadableField(name: "unlimited")] on '.UnlimitedUpload::class.'::$file: no maximum size');

        $this->boot(['mapped_classes' => [UnlimitedUpload::class]]);
    }

    public function testDefaultMaximumSizeCoversMappingsWithoutOne(): void
    {
        $container = $this->boot(['mapped_classes' => [UnlimitedUpload::class], 'defaults' => ['max_size' => '2Mi']])->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $registry = $container->get(MappingRegistry::class);
        self::assertInstanceOf(MappingRegistry::class, $registry);

        self::assertSame(2 * 1024 * 1024, $registry->get('unlimited')->maxSize);
        self::assertNull($registry->get('document_file')->maxSize, '#[PresignedFile(maxSize: "1M")] decides');
    }

    public function testFailsOnUploadTtlOutlivingTheClaimDeadline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('#[UploadableField(name: "document_file")] on %s::$file: uploadTtl must be between 1 and 604800 seconds and shorter than "defaults.claim_ttl" (400).', Document::class));

        $this->boot(['defaults' => ['claim_ttl' => 400]]);
    }

    public function testFailsOnStorageWithoutConditionalPutAndNoChecksum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage "private" has "conditional_put" disabled, which requires "checksum: true"');

        $this->boot(['storages' => ['private' => ['conditional_put' => false]]]);
    }

    /**
     * @param array<string, mixed>      $config
     * @param array<string, mixed>|null $storages replaces the default storages
     */
    private function boot(array $config = [], ?array $storages = null): TestKernel
    {
        $kernel = new TestKernel('test', true, array_filter(['config' => $config, 'storages' => $storages], static fn (?array $v): bool => null !== $v && [] !== $v));
        $kernel->boot();

        return $kernel;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir().'/vadage_presigned_uploader');
        TestKernel::restoreExceptionHandler();
    }
}
