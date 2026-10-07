<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CsrfTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['csrf' => true];

    public function testTheUploadEndpointsRequireTheTokenOfTheWidget(): void
    {
        $body = ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain'];
        self::assertSame(403, $this->postJson('/uploads/document_file', $body)[0]);

        $crawler = $this->client->request('GET', '/documents/new');
        $token = $crawler->filter('[data-controller="vadage--presigned-uploader-bundle--upload"]')->first()->attr('data-vadage--presigned-uploader-bundle--upload-csrf-token-value');
        self::assertNotNull($token);

        self::assertSame(201, $this->postJson('/uploads/document_file', $body, ['HTTP_X_CSRF_TOKEN' => $token])[0]);
    }
}
