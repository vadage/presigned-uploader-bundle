<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

use AsyncAws\Core\Configuration;
use AsyncAws\Core\Credentials\CredentialProvider;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\Input\PutObjectRequest;
use AsyncAws\S3\S3Client;
use Psr\Clock\ClockInterface;
use Vadage\PresignedUploaderBundle\Exception\StorageException;

/**
 * A bucket in an S3-compatible storage (AWS S3, Cloudflare R2, SeaweedFS, ...): presigns and verifies
 * uploads, and is the default object store of the storage.
 *
 * @internal
 */
final readonly class S3Storage implements ObjectStoreInterface
{
    private SigV4QueryPresigner $presigner;

    public function __construct(
        private string $name,
        private S3Client $client,
        private string $bucket,
        private CredentialProvider $credentialProvider,
        private ClockInterface $clock,
        private ?string $publicUrl = null,
        private bool $conditionalPut = true,
    ) {
        $this->presigner = new SigV4QueryPresigner();
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The returned URL only accepts exactly this size, MIME type and checksum, and (with conditional_put) only one write.
     */
    public function presignPut(string $key, int $size, string $mimeType, ?string $sha256, \DateTimeImmutable $expiresAt): PresignedRequest
    {
        // Content-Length is signed but not returned: browsers derive it from the body and forbid setting it.
        $clientHeaders = ['Content-Type' => $mimeType];
        if ($this->conditionalPut) {
            $clientHeaders['If-None-Match'] = '*';
        }
        if (null !== $sha256) {
            $clientHeaders['x-amz-checksum-sha256'] = $sha256;
        }

        $configuration = $this->client->getConfiguration();
        $credentials = $this->credentialProvider->getCredentials($configuration)
            ?? throw new StorageException(\sprintf('No credentials found for storage "%s".', $this->name));

        $now = $this->clock->now();
        $url = $this->presigner->presign(
            'PUT',
            $this->objectUrl($key),
            ['Content-Length' => (string) $size] + $clientHeaders,
            $credentials,
            (string) $configuration->get(Configuration::OPTION_REGION),
            $now,
            max(1, $expiresAt->getTimestamp() - $now->getTimestamp()),
        );

        return new PresignedRequest('PUT', $url, $clientHeaders, $expiresAt);
    }

    public function head(string $key, bool $withChecksum = false): ?ObjectMetadata
    {
        $input = ['Bucket' => $this->bucket, 'Key' => $key];
        if ($withChecksum) {
            $input['ChecksumMode'] = 'ENABLED';
        }

        $result = $this->client->headObject($input);
        try {
            $result->resolve();
        } catch (NoSuchKeyException) {
            return null;
        } catch (ClientException $e) {
            // HEAD responses have no body, so some storages' 404s are not mapped to NoSuchKeyException.
            if (404 === $e->getResponse()->getStatusCode()) {
                return null;
            }

            throw $e;
        }

        return new ObjectMetadata((int) $result->getContentLength(), $result->getContentType(), $result->getChecksumSha256());
    }

    public function readStart(string $key, int $length): string
    {
        return $this->client
            ->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'Range' => \sprintf('bytes=0-%d', $length - 1)])
            ->getBody()
            ->getContentAsString();
    }

    public function readStream(string $key)
    {
        return $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key])->getBody()->getContentAsResource();
    }

    public function writeStream(string $key, $contents, string $mimeType): void
    {
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $contents, 'ContentType' => $mimeType])->resolve();
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key])->resolve();
    }

    /**
     * Public URL when the storage has one, presigned GET otherwise.
     */
    public function url(string $key, ?\DateTimeImmutable $expiresAt = null): string
    {
        if (null !== $this->publicUrl) {
            return rtrim($this->publicUrl, '/').'/'.self::encodeKey($key);
        }

        return $this->client->presign(
            new GetObjectRequest(['Bucket' => $this->bucket, 'Key' => $key]),
            $expiresAt ?? $this->clock->now()->modify('+5 minutes'),
        );
    }

    /**
     * Server-side copy, for storages using the same client (account and endpoint).
     */
    public function copyFrom(ObjectStoreInterface $source, string $sourceKey, string $key): bool
    {
        if (!$source instanceof self || $source->client !== $this->client) {
            return false;
        }

        $this->client->copyObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'CopySource' => $source->bucket.'/'.self::encodeKey($sourceKey),
        ])->resolve();

        return true;
    }

    private function objectUrl(string $key): string
    {
        // Reuses async-aws' endpoint resolution (path style, custom endpoints, regions).
        $url = $this->client->presign(new PutObjectRequest(['Bucket' => $this->bucket, 'Key' => $key]));

        return explode('?', $url, 2)[0];
    }

    private static function encodeKey(string $key): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }
}
