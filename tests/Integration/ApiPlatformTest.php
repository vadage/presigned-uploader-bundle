<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Vadage\PresignedUploaderBundle\Event\PreSignEvent;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;

/**
 * Document is an API Platform resource under /apip, see TestKernel.
 */
#[Group('integration')]
final class ApiPlatformTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['doctrine' => true, 'api_platform' => true, 'config' => ['graphql' => true]];

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

    public function testGraphQlPresignsVerifiesAndClaimsAnUpload(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl(
            'mutation ($input: createPresignedUploadInput!) { createPresignedUpload(input: $input) { presignedUpload { uploadId state method url headers expiresAt } } }',
            ['input' => ['mapping' => 'document_file', 'filename' => 'a.txt', 'size' => 7, 'mimeType' => 'text/plain']],
        );
        $presign = self::at($data, 'data', 'createPresignedUpload', 'presignedUpload');
        self::assertIsArray($presign);
        self::assertSame('pending', self::str($presign, 'state'));
        self::assertSame('PUT', self::str($presign, 'method'));
        self::assertIsArray($presign['headers'] ?? null);
        $headers = [];
        foreach ($presign['headers'] as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value);
            $headers[$name] = $value;
        }
        self::assertSame('text/plain', $headers['Content-Type'] ?? null);
        $uploadId = self::str($presign, 'uploadId');

        self::assertSame(200, $this->put(['url' => self::str($presign, 'url'), 'method' => 'PUT', 'headers' => $headers], 'content'));

        $data = $this->graphQl(
            'mutation ($id: String!) { verifyPresignedUpload(input: {uploadId: $id}) { presignedUpload { uploadId state violations { propertyPath message } } } }',
            ['id' => $uploadId],
        );
        self::assertSame('verified', self::str($data, 'data', 'verifyPresignedUpload', 'presignedUpload', 'state'));
        self::assertSame([], self::at($data, 'data', 'verifyPresignedUpload', 'presignedUpload', 'violations'));

        $data = $this->graphQl('mutation ($file: String) { createDocument(input: {file: $file}) { document { file { originalName } } } }', ['file' => $uploadId]);
        self::assertSame('a.txt', self::str($data, 'data', 'createDocument', 'document', 'file', 'originalName'));
        self::assertNull($this->state($uploadId), 'Claimed');
    }

    public function testGraphQlVerifyReportsARejectedFile(): void
    {
        self::requireGraphQlSupport();
        $presign = $this->presign('document_file', 'a.txt', self::PNG, 'text/plain');
        self::assertSame(200, $this->put($presign, self::PNG));

        $data = $this->graphQl(
            'mutation ($id: String!) { verifyPresignedUpload(input: {uploadId: $id}) { presignedUpload { state violations { propertyPath message } } } }',
            ['id' => $presign['uploadId']],
        );

        self::assertSame('rejected', self::str($data, 'data', 'verifyPresignedUpload', 'presignedUpload', 'state'));
        self::assertNotSame('', self::str($data, 'data', 'verifyPresignedUpload', 'presignedUpload', 'violations', 0, 'message'));
    }

    public function testGraphQlVerifyOnlyAnswersTheOwner(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl('mutation { verifyPresignedUpload(input: {uploadId: "unknown"}) { presignedUpload { state } } }');

        self::assertSame(404, self::at($data, 'errors', 0, 'extensions', 'status'));
    }

    public function testGraphQlPresignReportsViolations(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl('mutation { createPresignedUpload(input: {mapping: "document_file", filename: "a.txt", size: 2000000, mimeType: "text/plain"}) { presignedUpload { uploadId } } }');

        self::assertSame(422, self::at($data, 'errors', 0, 'extensions', 'status'));
        self::assertStringStartsWith('The file is too large', self::str($data, 'errors', 0, 'extensions', 'violations', 0, 'message'));
    }

    public function testGraphQlPresignRejectsFractionalSizes(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl('mutation { createPresignedUpload(input: {mapping: "document_file", filename: "a.txt", size: 1.5, mimeType: "text/plain"}) { presignedUpload { uploadId } } }');

        self::assertSame(400, self::at($data, 'errors', 0, 'extensions', 'status'));
    }

    public function testGraphQlPresignReportsUnknownMappingsAndDenials(): void
    {
        self::requireGraphQlSupport();

        $data = $this->graphQl('mutation { createPresignedUpload(input: {mapping: "unknown", filename: "a.txt", size: 5, mimeType: "text/plain"}) { presignedUpload { uploadId } } }');
        self::assertSame(404, self::at($data, 'errors', 0, 'extensions', 'status'));

        self::service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PreSignEvent::class, static function (PreSignEvent $event): void {
            $event->deny('Too many uploads, please try again later.', 429);
        });
        $data = $this->graphQl('mutation { createPresignedUpload(input: {mapping: "document_file", filename: "a.txt", size: 5, mimeType: "text/plain"}) { presignedUpload { uploadId } } }');
        self::assertSame(429, self::at($data, 'errors', 0, 'extensions', 'status'));
        self::assertSame('Too many uploads, please try again later.', self::str($data, 'errors', 0, 'message'));
    }

    public function testGraphQlResourcesLeaveTheMappingPathsAlone(): void
    {
        self::requireGraphQlSupport();

        $directories = self::getContainer()->getParameter('api_platform.resource_class_directories');
        self::assertIsArray($directories);
        foreach ($directories as $directory) {
            self::assertIsString($directory);
            self::assertStringNotContainsString('ApiPlatform/Resource', $directory);
        }
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
