<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;

/**
 * Document is an API Platform resource under /apip, see TestKernel.
 */
#[Group('integration')]
final class ApiPlatformTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['doctrine' => true, 'api_platform' => true];

    private const JSON = ['HTTP_ACCEPT' => 'application/json'];

    protected function setUp(): void
    {
        self::requireDatabaseDriver();
        parent::setUp();
        $this->createSchema();
    }

    public function testRestWritesAnUploadIdAndReadsTheObject(): void
    {
        $token = $this->uploadText('a.txt', 'content');

        [$status, $data] = $this->requestJson('POST', '/apip/documents', ['file' => $token], self::JSON);
        self::assertSame(201, $status);
        $id = $data['id'] ?? null;
        self::assertIsInt($id);

        $this->client->request('GET', '/apip/documents/'.$id, server: self::JSON);
        self::assertResponseIsSuccessful();
        $data = $this->responseJson();
        self::assertIsArray($data['file'] ?? null);
        self::assertSame(['url', 'size', 'mimeType', 'originalName', 'sha256', 'uploadedAt'], array_keys($data['file']));
        self::assertSame('content', HttpClient::create()->request('GET', self::str($data, 'file', 'url'))->getContent());
    }

    public function testRestRejectsObjectsSentByTheClient(): void
    {
        [$status] = $this->requestJson('POST', '/apip/documents', ['file' => ['storage' => 'private', 'key' => 'someone-else.txt', 'size' => 1, 'mimeType' => 'text/plain', 'originalName' => 'a.txt', 'sha256' => null, 'uploadedAt' => '2026-01-01T00:00:00+00:00']], self::JSON);

        self::assertSame(400, $status);
        self::assertSame(0, $this->em()->getRepository(Document::class)->count());
    }

    public function testRestSaysWhyAnUploadIdCannotBeUsed(): void
    {
        [$status, $data] = $this->requestJson('POST', '/apip/documents', ['file' => 'unknown'], self::JSON);

        self::assertSame(400, $status);
        self::assertSame('The upload does not exist.', self::str($data, 'detail'));
    }

    public function testRestRejectsAnUploadForAnotherProperty(): void
    {
        $token = $this->uploadText('a.txt', 'content');

        [$status, $data] = $this->requestJson('POST', '/apip/documents', ['image' => $token], self::JSON);

        self::assertSame(422, $status, 'Mapped with exceptionToStatus');
        self::assertSame('The upload was made for "document_file", it cannot be stored as "document_image".', self::str($data, 'detail'));
        self::assertSame(UploadState::Verified, $this->state($token));
    }

    public function testRestClearsTheFile(): void
    {
        $token = $this->uploadText('a.txt', 'content');
        $key = self::keyOfUpload($token);
        [, $data] = $this->requestJson('POST', '/apip/documents', ['file' => $token], self::JSON);
        $id = $data['id'] ?? null;
        self::assertIsInt($id);

        $this->client->request('PATCH', '/apip/documents/'.$id, server: ['CONTENT_TYPE' => 'application/merge-patch+json'] + self::JSON, content: '{"file": null}');

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseJson()['file'] ?? null, 'Left out: API Platform skips null values by default');
        self::assertNull($this->em()->find(Document::class, $id)?->file);
        self::assertFalse($this->objectExists('test-private', $key));
    }

    public function testOpenApiDescribesUploadIdsAndStoredObjects(): void
    {
        $this->client->request('GET', '/apip/docs.jsonopenapi');
        self::assertResponseIsSuccessful();
        $schemas = $this->responseJson();

        self::assertSame(['string', 'null'], self::at($schemas, 'components', 'schemas', 'Document-document.write', 'properties', 'file', 'type'));
        self::assertSame(['object', 'null'], self::at($schemas, 'components', 'schemas', 'Document-document.read', 'properties', 'file', 'type'));
        $properties = self::at($schemas, 'components', 'schemas', 'Document-document.read', 'properties', 'file', 'properties');
        self::assertIsArray($properties);
        self::assertSame(['url', 'size', 'mimeType', 'originalName', 'sha256', 'uploadedAt'], array_keys($properties));
    }

    public function testGraphQlWritesAnUploadIdAndReadsTheObject(): void
    {
        self::requireGraphQlSupport();
        $token = $this->uploadText('a.txt', 'content');
        $key = self::keyOfUpload($token);

        $data = $this->graphQl('mutation ($file: String) { createDocument(input: {file: $file}) { document { id file { url size originalName } } } }', ['file' => $token]);
        self::assertSame('a.txt', self::str($data, 'data', 'createDocument', 'document', 'file', 'originalName'));
        self::assertSame(7, self::at($data, 'data', 'createDocument', 'document', 'file', 'size'));
        $id = self::str($data, 'data', 'createDocument', 'document', 'id');

        $data = $this->graphQl('query ($id: ID!) { document(id: $id) { file { url mimeType uploadedAt } } }', ['id' => $id]);
        self::assertSame('content', HttpClient::create()->request('GET', self::str($data, 'data', 'document', 'file', 'url'))->getContent());
        self::assertSame('text/plain', self::str($data, 'data', 'document', 'file', 'mimeType'));

        $data = $this->graphQl('mutation ($id: ID!) { updateDocument(input: {id: $id, file: null}) { document { file { url } } } }', ['id' => $id]);
        self::assertNull(self::at($data, 'data', 'updateDocument', 'document', 'file'));
        self::assertFalse($this->objectExists('test-private', $key), 'Cleared object deleted');
    }

    public function testGraphQlSaysWhyAnUploadIdCannotBeUsed(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl('mutation { createDocument(input: {file: "unknown"}) { document { id } } }');

        self::assertSame('The upload does not exist.', self::str($data, 'errors', 0, 'message'));
        self::assertSame(0, $this->em()->getRepository(Document::class)->count());
    }

    private static function requireGraphQlSupport(): void
    {
        if (!(new \ReflectionClass(TypeConverterInterface::class))->hasMethod('convertPhpType')) {
            self::markTestSkipped('GraphQL support requires API Platform 5.');
        }
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<mixed>
     */
    private function graphQl(string $query, array $variables = []): array
    {
        [$status, $data] = $this->requestJson('POST', '/apip/graphql', ['query' => $query, 'variables' => (object) $variables]);
        self::assertSame(200, $status);

        return $data;
    }
}
