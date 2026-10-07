<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use AsyncAws\Core\Credentials\Credentials;
use PHPUnit\Framework\TestCase;
use Vadage\PresignedUploaderBundle\Storage\SigV4QueryPresigner;

final class SigV4QueryPresignerTest extends TestCase
{
    /**
     * Example from the AWS documentation "Authenticating Requests: Using Query Parameters".
     */
    public function testMatchesAwsReferenceExample(): void
    {
        $url = (new SigV4QueryPresigner())->presign(
            'GET',
            'https://examplebucket.s3.amazonaws.com/test.txt',
            [],
            new Credentials('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY'),
            'us-east-1',
            new \DateTimeImmutable('2013-05-24T00:00:00Z'),
            86400,
        );

        self::assertStringEndsWith('&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
        self::assertStringContainsString('X-Amz-SignedHeaders=host', $url);
    }

    public function testSignsGivenHeaders(): void
    {
        $url = (new SigV4QueryPresigner())->presign(
            'PUT',
            'http://localhost:8333/bucket/some%20key.png',
            ['Content-Type' => 'image/png', 'Content-Length' => '5', 'If-None-Match' => '*'],
            new Credentials('key', 'secret', 'session'),
            'auto',
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            300,
        );

        self::assertStringStartsWith('http://localhost:8333/bucket/some%20key.png?', $url);
        self::assertStringContainsString('X-Amz-SignedHeaders=content-length%3Bcontent-type%3Bhost%3Bif-none-match', $url);
        self::assertStringContainsString('X-Amz-Security-Token=session', $url);
        self::assertStringContainsString('X-Amz-Expires=300', $url);
    }
}
