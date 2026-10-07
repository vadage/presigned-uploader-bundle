<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

final class StoredObjectTest extends TestCase
{
    private const ROW = [
        'storage' => 'documents',
        'key' => 'cv/a.pdf',
        'size' => 77,
        'mimeType' => 'application/pdf',
        'originalName' => 'cv.pdf',
        'sha256' => null,
        'uploadedAt' => '2026-10-06T15:12:11+00:00',
    ];

    public function testRoundTrip(): void
    {
        self::assertSame(self::ROW, StoredObject::fromArray(self::ROW)->toArray());
    }

    public function testIgnoresUnknownKeys(): void
    {
        self::assertSame(self::ROW, StoredObject::fromArray(self::ROW + ['width' => 640, 'etag' => '"abc"'])->toArray());
    }

    public function testOptionalFieldsMayBeMissing(): void
    {
        $row = self::ROW;
        unset($row['sha256']);

        self::assertNull(StoredObject::fromArray($row)->getSha256());
    }

    public function testRejectsRowsWithoutRequiredFields(): void
    {
        $row = self::ROW;
        unset($row['key']);

        $this->expectException(\UnexpectedValueException::class);
        StoredObject::fromArray($row);
    }
}
