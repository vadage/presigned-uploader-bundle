<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use AsyncAws\Core\Credentials\ConfigurationProvider;
use AsyncAws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;
use Vadage\PresignedUploaderBundle\Event\UploadRejectedEvent;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Storage\S3Storage;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;
use Vadage\PresignedUploaderBundle\Tests\App\InMemoryPendingUploadRepository;
use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;

/**
 * The checksum comparison needs a storage that returns stored checksums, which SeaweedFS does not.
 */
final class UploadVerifierTest extends TestCase
{
    private const CONTENT = 'hello';

    /** @var list<string> */
    private array $requests = [];

    /** @var list<UploadRejectedEvent> */
    private array $rejections = [];

    public function testObjectWithAnotherChecksumThanAnnouncedIsRejected(): void
    {
        $upload = $this->upload();
        $verifier = $this->verifier(base64_encode(hash('sha256', 'HELLO', true)));

        $result = $verifier->verify($upload);

        self::assertSame(UploadState::Rejected, $result->state);
        self::assertSame('The stored checksum does not match the announced checksum.', $result->violations[0]?->getMessage());
        self::assertSame(UploadState::Rejected, $upload->getState());
        self::assertSame(['HEAD', 'DELETE'], $this->requests, 'The object is deleted');
        self::assertCount(1, $this->rejections);
    }

    public function testObjectWithTheAnnouncedChecksumIsVerified(): void
    {
        $upload = $this->upload();

        $result = $this->verifier(base64_encode(hash('sha256', self::CONTENT, true)))->verify($upload);

        self::assertSame(UploadState::Verified, $result->state);
        self::assertSame(['HEAD'], $this->requests);
        self::assertSame([], $this->rejections);
    }

    private function upload(): PendingUpload
    {
        $now = new \DateTimeImmutable();

        return new PendingUpload(Uuid::v7(), 'document_file', 'user:alice', 'private', 'a.txt', 'private', 'a.txt', 'a.txt', \strlen(self::CONTENT), 'text/plain', base64_encode(hash('sha256', self::CONTENT, true)), $now, $now->modify('+5 minutes'), $now->modify('+1 day'));
    }

    private function verifier(string $storedChecksum): UploadVerifier
    {
        $http = new MockHttpClient(function (string $method) use ($storedChecksum): MockResponse {
            $this->requests[] = $method;

            return 'HEAD' === $method
                ? new MockResponse('', ['response_headers' => ['Content-Length' => (string) \strlen(self::CONTENT), 'Content-Type' => 'text/plain', 'x-amz-checksum-sha256' => $storedChecksum]])
                : new MockResponse('', ['http_code' => 204]);
        });
        $client = new S3Client(['endpoint' => 'http://storage.test', 'pathStyleEndpoint' => 'true', 'region' => 'us-east-1', 'accessKeyId' => 'key', 'accessKeySecret' => 'secret'], null, $http);
        $clock = new MockClock();
        $storage = new S3Storage('private', $client, 'bucket', new ConfigurationProvider(), $clock);
        $storages = new StorageRegistry(new ServiceLocator(['private' => static fn (): S3Storage => $storage]), new ServiceLocator(['private' => static fn (): S3Storage => $storage]), ['private' => 'bucket']);

        $repository = new InMemoryPendingUploadRepository();
        $mapping = new UploadMapping('document_file', Document::class, 'file', 'private', 'namer', '', true, null, false, 3600, true, true);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(UploadRejectedEvent::class, function (UploadRejectedEvent $event): void {
            $this->rejections[] = $event;
        });

        return new UploadVerifier(new MappingRegistry([$mapping]), $storages, $repository, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $dispatcher, $clock);
    }
}
