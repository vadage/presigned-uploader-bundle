<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Storage;

use AsyncAws\Core\Credentials\Credentials;

/**
 * Creates AWS Signature Version 4 query-string signatures (presigned URLs).
 *
 * async-aws' own presigner deliberately drops Content-Type, Content-Length and
 * conditional headers from the signature. This bundle needs exactly those headers
 * signed, so the storage rejects uploads that differ from what was validated.
 *
 * @internal
 */
final class SigV4QueryPresigner
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    /**
     * @param array<string, string> $headers headers the client must send, all of them get signed
     *
     * @return string the presigned URL
     */
    public function presign(
        string $method,
        string $url,
        array $headers,
        Credentials $credentials,
        string $region,
        \DateTimeImmutable $now,
        int $expiresInSeconds,
        string $service = 's3',
    ): string {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host']) || isset($parts['query'])) {
            throw new \InvalidArgumentException(\sprintf('Cannot presign invalid URL "%s".', $url));
        }

        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = '' === ($parts['path'] ?? '') ? '/' : $parts['path'];

        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $scope = \sprintf('%s/%s/%s/aws4_request', $date, $region, $service);

        $canonicalHeaders = ['host' => $host];
        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        ksort($canonicalHeaders, \SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $query = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $credentials->getAccessKeyId().'/'.$scope,
            'X-Amz-Date' => $now->format('Ymd\THis\Z'),
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => $signedHeaders,
        ];
        if (null !== $sessionToken = $credentials->getSessionToken()) {
            $query['X-Amz-Security-Token'] = $sessionToken;
        }
        $canonicalQuery = self::canonicalQuery($query);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            implode('', array_map(static fn (string $k, string $v): string => $k.':'.$v."\n", array_keys($canonicalHeaders), $canonicalHeaders)),
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [self::ALGORITHM, $query['X-Amz-Date'], $scope, hash('sha256', $canonicalRequest)]);

        $key = 'AWS4'.$credentials->getSecretKey();
        foreach ([$date, $region, $service, 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $signature = hash_hmac('sha256', $stringToSign, $key);

        return \sprintf('%s://%s%s?%s&X-Amz-Signature=%s', $parts['scheme'], $host, $path, $canonicalQuery, $signature);
    }

    /**
     * @param array<string, string> $query
     */
    private static function canonicalQuery(array $query): string
    {
        $encoded = [];
        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode((string) $value);
        }
        ksort($encoded, \SORT_STRING);

        return implode('&', array_map(static fn (string $k, string $v): string => $k.'='.$v, array_keys($encoded), $encoded));
    }
}
