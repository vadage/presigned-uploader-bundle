<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Vadage\PresignedUploaderBundle\ApiPlatform\CreatePresignedUploadProcessor;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;

#[Group('integration')]
final class GraphQlDisabledTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['doctrine' => true, 'api_platform' => true];

    protected function setUp(): void
    {
        self::requireDatabaseDriver();
        parent::setUp();
    }

    public function testTheMutationsAreOptIn(): void
    {
        if (!VadagePresignedUploaderBundle::graphQlAvailable()) {
            self::markTestSkipped('GraphQL support requires API Platform 5.');
        }

        [$status, $data] = $this->requestJson('POST', '/apip/graphql', ['query' => 'mutation { createPresignedUpload(input: {mapping: "document_file", filename: "a.txt", size: 5, mimeType: "text/plain"}) { presignedUpload { uploadId } } }']);

        self::assertSame(200, $status);
        self::assertStringContainsString('createPresignedUpload', self::str($data, 'errors', 0, 'message'));
        self::assertFalse(self::getContainer()->has(CreatePresignedUploadProcessor::class));
    }
}
