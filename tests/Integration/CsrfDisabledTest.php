<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CsrfDisabledTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['csrf' => true, 'config' => ['csrf_protection' => false]];

    public function testTheUploadEndpointsCanBeUsedWithoutToken(): void
    {
        self::assertSame(201, $this->postJson('/uploads/document_file', ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain'])[0]);

        $this->client->request('GET', '/documents/new');
        self::assertSelectorNotExists('[data-vadage--presigned-uploader-bundle--upload-csrf-token-value]');
    }
}
