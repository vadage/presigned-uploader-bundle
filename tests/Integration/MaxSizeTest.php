<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Vadage\PresignedUploaderBundle\Tests\App\Dto\UnlimitedUpload;

#[Group('integration')]
final class MaxSizeTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['config' => ['mapped_classes' => [UnlimitedUpload::class], 'defaults' => ['max_size' => 4]]];

    public function testDefaultMaximumSizeLimitsMappingsWithoutConstraint(): void
    {
        [$status, $data] = $this->postJson('/uploads/unlimited', ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain']);

        self::assertSame(422, $status);
        self::assertSame('size', self::str($data, 'violations', 0, 'propertyPath'));
        self::assertSame(201, $this->postJson('/uploads/unlimited', ['filename' => 'a.txt', 'size' => 4, 'mimeType' => 'text/plain'])[0]);
    }

    public function testMappingsWithAConstraintAreNotLimitedByTheDefault(): void
    {
        self::assertSame(201, $this->postJson('/uploads/document_file', ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain'])[0], 'Limited by #[PresignedFile(maxSize: "1M")], not by "defaults.max_size"');
    }
}
