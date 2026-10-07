<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use AsyncAws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\KernelInterface;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Tests\App\TestKernel;
use Vadage\PresignedUploaderBundle\Upload\SweepResult;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;
use Vadage\PresignedUploaderBundle\Upload\UploadSweeper;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;
use Vadage\PresignedUploaderBundle\Webhook\AbstractSignedRequestParser;

/**
 * Runs against SeaweedFS: `docker compose up -d --wait`.
 */
abstract class IntegrationTestCase extends WebTestCase
{
    protected const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    protected KernelBrowser $client;

    /** @var array{doctrine?: bool, config?: array<string, mixed>} */
    protected static array $kernelOptions = [];

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, static::$kernelOptions);
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        foreach (['test-private', 'test-public', 'test-quarantine'] as $bucket) {
            if (!$this->s3()->bucketExists(['Bucket' => $bucket])->isSuccess()) {
                $this->s3()->createBucket(['Bucket' => $bucket])->resolve();
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestKernel::restoreExceptionHandler();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected static function service(string $class, ?string $id = null): object
    {
        $service = static::getContainer()->get($id ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * For tests with Doctrine: call before parent::setUp().
     */
    protected static function requireDatabaseDriver(): void
    {
        $driver = 'pdo_'.match (parse_url(TestKernel::databaseUrl(), \PHP_URL_SCHEME)) {
            'mysql' => 'mysql',
            'postgresql', 'postgres', 'pgsql' => 'pgsql',
            default => 'sqlite',
        };
        if (!\extension_loaded($driver)) {
            self::markTestSkipped(\sprintf('The %s extension is required for DATABASE_URL.', $driver));
        }
    }

    protected function createSchema(): void
    {
        $em = $this->em();
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function em(): EntityManagerInterface
    {
        return self::service(EntityManagerInterface::class, 'doctrine.orm.entity_manager');
    }

    protected function s3(): S3Client
    {
        return self::service(S3Client::class, 'test.s3_client');
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     *
     * @return array{int, array<mixed>}
     */
    protected function postJson(string $url, array $body = [], array $server = []): array
    {
        return $this->requestJson('POST', $url, $body, $server);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     *
     * @return array{int, array<mixed>}
     */
    protected function requestJson(string $method, string $url, array $body = [], array $server = []): array
    {
        $this->client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'] + $server, content: json_encode($body, \JSON_THROW_ON_ERROR));

        return [$this->client->getResponse()->getStatusCode(), $this->responseJson()];
    }

    /**
     * @return array<mixed>
     */
    protected function responseJson(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * Reads a string at a path like ['file', 'key'].
     *
     * @param array<mixed> $data
     */
    protected static function str(array $data, string|int ...$path): string
    {
        $value = self::at($data, ...$path);
        self::assertIsString($value);

        return $value;
    }

    /**
     * Reads the value at a path like ['file', 'size'].
     *
     * @param array<mixed> $data
     */
    protected static function at(array $data, string|int ...$path): mixed
    {
        $value = $data;
        foreach ($path as $segment) {
            self::assertIsArray($value);
            self::assertArrayHasKey($segment, $value);
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array{uploadId: string, url: string, method: string, headers: array<string, string>}
     */
    protected function presign(string $mapping, string $filename, string $content, string $mimeType, bool $checksum = false): array
    {
        $body = ['filename' => $filename, 'size' => \strlen($content), 'mimeType' => $mimeType];
        if ($checksum) {
            $body['sha256'] = base64_encode(hash('sha256', $content, true));
        }

        [$status, $data] = $this->postJson('/uploads/'.$mapping, $body);
        self::assertSame(201, $status);
        self::assertIsArray($data['headers'] ?? null);
        $headers = [];
        foreach ($data['headers'] as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value);
            $headers[$name] = $value;
        }
        self::assertSame('/uploads/'.rawurlencode(self::str($data, 'uploadId')).'/verify', self::str($data, 'verifyUrl'));

        return [
            'uploadId' => self::str($data, 'uploadId'),
            'url' => self::str($data, 'url'),
            'method' => self::str($data, 'method'),
            'headers' => $headers,
        ];
    }

    /**
     * Uploads like a browser: exactly the returned headers, plus the body.
     *
     * @param array{url: string, method: string, headers: array<string, string>} $presign
     * @param array<string, string>                                              $overrideHeaders
     */
    protected function put(array $presign, string $content, array $overrideHeaders = []): int
    {
        return HttpClient::create()->request($presign['method'], $presign['url'], [
            'headers' => array_replace($presign['headers'], $overrideHeaders),
            'body' => $content,
        ])->getStatusCode();
    }

    /**
     * @return array{int, array<mixed>}
     */
    protected function verify(string $uploadId): array
    {
        return $this->postJson('/uploads/'.rawurlencode($uploadId).'/verify');
    }

    /**
     * A verified upload of a text file for Document::$file.
     */
    protected function uploadText(string $filename, string $content): string
    {
        $presign = $this->presign('document_file', $filename, $content, 'text/plain');
        self::assertSame(200, $this->put($presign, $content));
        self::assertSame(200, $this->verify($presign['uploadId'])[0]);

        return $presign['uploadId'];
    }

    protected static function keyOfUpload(string $token): string
    {
        $upload = self::service(UploadManager::class)->findByToken($token);
        self::assertNotNull($upload);

        return $upload->getKey();
    }

    /**
     * The object key a presigned URL writes to.
     */
    protected static function keyOf(string $url, string $bucket): string
    {
        return substr(rawurldecode((string) parse_url($url, \PHP_URL_PATH)), \strlen('/'.$bucket.'/'));
    }

    /**
     * Forwards a storage event like a relay (e.g. an R2 queue consumer worker) would.
     *
     * @param 'r2'|'s3' $provider
     */
    protected function sendWebhook(string $bucket, string $key, string $provider = 'r2'): void
    {
        $payload = 'r2' === $provider
            ? ['action' => 'PutObject', 'bucket' => $bucket, 'object' => ['key' => $key]]
            : ['Records' => [['eventName' => 'ObjectCreated:Put', 's3' => ['bucket' => ['name' => $bucket], 'object' => ['key' => rawurlencode($key)]]]]];
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);
        $timestamp = time();
        $this->client->request('POST', '/webhook/'.VadagePresignedUploaderBundle::WEBHOOK_TYPES[$provider], server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => AbstractSignedRequestParser::sign($body, $timestamp, 'webhook-secret'),
        ], content: $body);
    }

    /**
     * Runs the cleanup at a point in time relative to now, e.g. "+16 minutes".
     */
    protected function sweepAt(string $modifier): SweepResult
    {
        Clock::set(new MockClock($modifier));
        try {
            $result = self::service(UploadSweeper::class, 'vadage_presigned_uploader.sweeper')->sweep();
        } finally {
            Clock::set(new NativeClock());
        }
        self::assertSame([], $result->failures);

        return $result;
    }

    protected function state(string $uploadId): ?UploadState
    {
        return self::service(UploadManager::class)->findByToken($uploadId)?->getState();
    }

    protected function ownerOf(string $uploadId): string
    {
        $upload = self::service(UploadManager::class)->findByToken($uploadId);
        self::assertNotNull($upload);

        return $upload->getOwnerId();
    }

    protected function objectExists(string $bucket, string $key): bool
    {
        return $this->s3()->objectExists(['Bucket' => $bucket, 'Key' => $key])->isSuccess();
    }
}
