<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Service\ResetInterface;
use Vadage\PresignedUploaderBundle\Model\ObjectTombstone;
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Repository\DoctrinePendingUploadRepository;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;
use Vadage\PresignedUploaderBundle\Tests\App\TestKernel;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * Runs against the database in DATABASE_URL (SQLite in memory by default), see compose.yaml.
 */
#[Group('integration')]
final class DoctrineFlowTest extends IntegrationTestCase
{
    protected static array $kernelOptions = ['doctrine' => true];

    protected function setUp(): void
    {
        self::requireDatabaseDriver();
        parent::setUp();
        $this->createSchema();
    }

    public function testSchemaIsInSyncAfterCreation(): void
    {
        // A non-empty diff means every application would get the same migration generated over and over.
        $em = $this->em();
        self::assertSame([], (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()));
    }

    public function testClaimReplaceAndRemove(): void
    {
        $id = $this->submit('new', $this->uploadText('first.txt', 'first content'));
        $firstKey = self::str($this->responseJson(), 'file', 'key');

        self::assertTrue($this->objectExists('test-private', $firstKey));
        self::assertSame(0, $this->em()->getRepository(PendingUpload::class)->count(), 'Released after the flush');

        $this->submit($id, $this->uploadText('second.txt', 'second content'));
        $secondKey = self::str($this->responseJson(), 'file', 'key');
        self::assertFalse($this->objectExists('test-private', $firstKey), 'Replaced object deleted');
        self::assertTrue($this->objectExists('test-private', $secondKey));

        $this->submit($id, '');
        self::assertSame($secondKey, self::str($this->responseJson(), 'file', 'key'), 'Submitting nothing keeps the object');

        $this->em()->clear();
        $document = $this->em()->find(Document::class, (int) $id);
        self::assertNotNull($document);
        self::assertSame('second.txt', $document->file?->getOriginalName());

        $this->em()->remove($document);
        $this->em()->flush();
        self::assertFalse($this->objectExists('test-private', $secondKey), 'Deleted with the entity');
    }

    public function testObjectsWrittenByTheApplicationAreDeletedWhenReplaced(): void
    {
        $this->s3()->putObject(['Bucket' => 'test-private', 'Key' => 'rendition/1.txt', 'Body' => 'first'])->resolve();
        $this->s3()->putObject(['Bucket' => 'test-private', 'Key' => 'rendition/2.txt', 'Body' => 'second'])->resolve();
        $object = static fn (string $key): StoredObject => new StoredObject('private', $key, 5, 'text/plain', 'a.txt', null, new \DateTimeImmutable());

        $document = new Document();
        $document->rendition = $object('rendition/1.txt');
        $this->em()->persist($document);
        $this->em()->flush();

        $document->rendition = $object('rendition/2.txt');
        $this->em()->flush();

        self::assertFalse($this->objectExists('test-private', 'rendition/1.txt'), 'Replaced object deleted');
        self::assertTrue($this->objectExists('test-private', 'rendition/2.txt'));
    }

    public function testWebhookFindsUploadByLocation(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        $this->sendWebhook('test-private', self::keyOf($presign['url'], 'test-private'));
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        self::assertSame(UploadState::Verified, self::service(UploadManager::class)->findByToken($presign['uploadId'])?->getState());
    }

    public function testDeleteCheckboxClearsTheObject(): void
    {
        $id = $this->submit('new', $this->uploadText('a.txt', 'some text'));
        $key = self::str($this->responseJson(), 'file', 'key');

        $this->submit($id, '', delete: true);

        self::assertNull($this->responseJson()['file'] ?? null);
        self::assertFalse($this->objectExists('test-private', $key));
    }

    public function testFailedFlushAbandonsItsClaims(): void
    {
        $token = $this->uploadText('a.txt', 'content');
        $key = self::keyOfUpload($token);
        $document = new Document();
        $document->file = $this->resolve($token);

        $em = $this->em();
        $em->getConnection()->executeStatement('DROP TABLE '.$em->getClassMetadata(Document::class)->getTableName());
        $em->persist($document);
        try {
            $em->flush();
            self::fail('The flush should fail.');
        } catch (TableNotFoundException) {
        }
        self::service(ManagerRegistry::class, 'doctrine')->resetManager();

        self::assertSame(UploadState::Claiming, $this->freshState($token));
        // Symfony resets services after each request and each message a worker handles.
        self::service(ResetInterface::class, 'services_resetter')->reset();
        self::assertSame(UploadState::Verified, $this->freshState($token), 'Claimable again right away');

        $this->sweepAt('+2 days');
        self::assertNull($this->freshState($token));
        self::assertFalse($this->objectExists('test-private', $key), 'Expired like any unclaimed upload');
    }

    /**
     * The flush listener commits a claim by deleting the record in the flush transaction. While that
     * transaction runs, reverting the claim (the cleanup command, another connection) has to wait for it.
     */
    public function testRevertingAClaimWaitsForTheTransactionThatCommitsIt(): void
    {
        $connection = $this->em()->getConnection();
        $platform = $connection->getDatabasePlatform();
        $lockTimeout = match (true) {
            $platform instanceof MySQLPlatform => 'SET SESSION innodb_lock_wait_timeout = 1',
            $platform instanceof PostgreSQLPlatform => "SET lock_timeout = '1s'",
            default => self::markTestSkipped('Needs row locks shared by two connections.'),
        };

        $token = $this->uploadText('a.txt', 'content');
        $upload = self::service(UploadManager::class)->findByToken($token);
        self::assertNotNull($upload);
        self::assertNotNull(self::service(UploadManager::class)->claim($this->resolve($token)));

        $em = $this->em();
        $connection->beginTransaction();
        $em->remove($upload);
        $em->flush();

        // The cleanup command, on a connection of its own.
        $dsn = new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']);
        $sweeperConnection = DriverManager::getConnection($dsn->parse(TestKernel::databaseUrl()));
        $sweeperConnection->executeStatement($lockTimeout);
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(new EntityManager($sweeperConnection, $em->getConfiguration()));
        $sweeper = new DoctrinePendingUploadRepository($registry);
        $stale = $sweeper->find($upload->getId());
        self::assertNotNull($stale, 'Still there until the claiming transaction commits');
        $claimedBefore = new \DateTimeImmutable('+1 hour');

        try {
            $sweeper->revertStaleClaim($stale, $claimedBefore);
            self::fail('Reverting must wait for the claiming transaction.');
        } catch (DriverException $e) {
            // DBAL maps MySQL's lock wait timeout, but not PostgreSQL's (SQLSTATE 55P03).
            self::assertTrue($e instanceof LockWaitTimeoutException || '55P03' === $e->getSQLState(), $e->getMessage());
        }

        $connection->commit();
        self::assertFalse($sweeper->revertStaleClaim($stale, $claimedBefore), 'The committed claim deleted the record');
        $sweeperConnection->close();
    }

    public function testMovingAnObjectToAnotherEntityKeepsIt(): void
    {
        $id = $this->submit('new', $this->uploadText('a.txt', 'moved'));
        $key = self::str($this->responseJson(), 'file', 'key');

        $em = $this->em();
        $old = $em->find(Document::class, (int) $id);
        self::assertNotNull($old);
        $new = new Document();
        $new->file = $old->file;
        $em->persist($new);
        $em->remove($old);
        $em->flush();

        $this->sweepAt('+1 hour');
        self::assertTrue($this->objectExists('test-private', $key));
    }

    public function testSwappingObjectsKeepsBoth(): void
    {
        $first = $this->submit('new', $this->uploadText('a.txt', 'first'));
        $firstKey = self::str($this->responseJson(), 'file', 'key');
        $second = $this->submit('new', $this->uploadText('b.txt', 'second'));
        $secondKey = self::str($this->responseJson(), 'file', 'key');

        $em = $this->em();
        $a = $em->find(Document::class, (int) $first);
        $b = $em->find(Document::class, (int) $second);
        self::assertNotNull($a);
        self::assertNotNull($b);
        [$a->file, $b->file] = [$b->file, $a->file];
        $em->flush();

        $this->sweepAt('+1 hour');
        self::assertTrue($this->objectExists('test-private', $firstKey));
        self::assertTrue($this->objectExists('test-private', $secondKey));
    }

    public function testUploadsInColumnsWithoutMappingAreRejected(): void
    {
        $token = $this->uploadText('a.txt', 'content');
        $document = new Document();
        $document->copy = $this->resolve($token);
        $this->em()->persist($document);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(Document::class.'::$copy holds an upload, but has no #[UploadableField]');
        $this->em()->flush();
    }

    public function testAClaimedObjectCanBeStoredAgainLater(): void
    {
        $token = $this->uploadText('a.txt', 'content');
        $em = $this->em();
        $object = $this->resolve($token);
        $original = new Document();
        $original->file = $object;
        $em->persist($original);
        $em->flush();

        $copy = new Document();
        $copy->copy = $object;
        $em->persist($copy);
        $em->flush();

        self::assertNotNull($copy->id);
    }

    public function testPresigningDoesNotFlushPendingChangesOfTheApplication(): void
    {
        $em = $this->em();
        $document = new Document();
        $em->persist($document);

        $this->presign('document_file', 'a.txt', 'content', 'text/plain');
        self::service(UploadManager::class)->presign('document_file', new UploadDescriptor('a.txt', 7, 'text/plain'), 'user:alice');

        self::assertNull($document->id, 'Still waiting for the application\'s own flush');
    }

    public function testOuterRollbackKeepsTheReplacedObject(): void
    {
        $id = $this->submit('new', $this->uploadText('first.txt', 'first'));
        $firstKey = self::str($this->responseJson(), 'file', 'key');
        $second = $this->uploadText('second.txt', 'second');

        $em = $this->em();
        $document = $em->find(Document::class, (int) $id);
        self::assertNotNull($document);
        $em->getConnection()->beginTransaction();
        $document->file = $this->resolve($second);
        $em->flush();
        $em->getConnection()->rollBack();

        self::assertTrue($this->objectExists('test-private', $firstKey));
        self::assertSame(UploadState::Verified, $this->freshState($second), 'The claim rolled back too');
        self::assertSame($firstKey, $em->find(Document::class, (int) $id)?->file?->getKey());

        $this->sweepAt('+1 minute');
        self::assertTrue($this->objectExists('test-private', $firstKey), 'No tombstone survived the rollback');
    }

    public function testOuterCommitDeletesTheReplacedObjectOnCleanup(): void
    {
        $id = $this->submit('new', $this->uploadText('first.txt', 'first'));
        $firstKey = self::str($this->responseJson(), 'file', 'key');
        $second = $this->uploadText('second.txt', 'second');

        $em = $this->em();
        $document = $em->find(Document::class, (int) $id);
        self::assertNotNull($document);
        $em->getConnection()->beginTransaction();
        $document->file = $this->resolve($second);
        $em->flush();
        self::assertTrue($this->objectExists('test-private', $firstKey), 'Not deleted before the commit');
        $em->getConnection()->commit();

        self::assertSame(1, $this->sweepAt('+1 minute')->deletedObjects);
        self::assertFalse($this->objectExists('test-private', $firstKey));
        self::assertNull($this->freshState($second), 'Claimed in the outer transaction');
    }

    public function testStagingObjectRecreatedThroughItsUrlIsDeletedAgain(): void
    {
        $presign = $this->presign('document_image', 'pixel.png', self::PNG, 'image/png', checksum: true);
        $stagingKey = self::keyOf($presign['url'], 'test-quarantine');
        self::assertSame(200, $this->put($presign, self::PNG));
        self::assertSame(200, $this->verify($presign['uploadId'])[0]);

        $this->client->request('POST', '/documents/new', ['document' => ['image' => ['token' => $presign['uploadId']]]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->objectExists('test-quarantine', $stagingKey), 'Deleted right after the commit');

        self::assertSame(200, $this->put($presign, self::PNG), 'The presigned URL is still valid');
        $this->sweepAt('+1 minute');
        self::assertFalse($this->objectExists('test-quarantine', $stagingKey), 'Deleted again while the URL is valid');
        self::assertSame(1, $this->em()->getRepository(ObjectTombstone::class)->count());

        $this->sweepAt('+6 minutes');
        self::assertSame(0, $this->em()->getRepository(ObjectTombstone::class)->count(), 'Removed once the URL expired');
    }

    public function testApiStoresAnUploadIdAndReadsTheObjectWithAUrl(): void
    {
        $token = $this->uploadText('a.txt', 'content');
        $key = self::keyOfUpload($token);

        [$status, $data] = $this->requestJson('POST', '/api/documents', ['file' => $token]);

        self::assertSame(200, $status);
        self::assertIsArray($data['file'] ?? null);
        self::assertSame(['url', 'size', 'mimeType', 'originalName', 'sha256', 'uploadedAt'], array_keys($data['file']), 'No storage, key or internal state');
        self::assertSame('a.txt', self::str($data, 'file', 'originalName'));
        self::assertSame('content', HttpClient::create()->request('GET', self::str($data, 'file', 'url'))->getContent());
        self::assertTrue($this->objectExists('test-private', $key));
        self::assertSame(0, $this->em()->getRepository(PendingUpload::class)->count(), 'Claimed by the flush');
    }

    public function testApiRejectsObjectsSentByTheClient(): void
    {
        $id = $this->submit('new', $this->uploadText('secret.txt', 'secret'));
        $em = $this->em();
        $em->clear();
        $secret = $em->find(Document::class, (int) $id)?->file;
        self::assertNotNull($secret);

        [$status, $data] = $this->requestJson('POST', '/api/documents', ['file' => $secret->toArray()]);

        self::assertSame(400, $status);
        self::assertSame('file', self::str($data, 'path'));
        self::assertSame(1, $this->em()->getRepository(Document::class)->count(), 'Nothing stored');
    }

    public function testApiRejectsUploadIdsItCannotClaim(): void
    {
        [$status, $data] = $this->requestJson('POST', '/api/documents', ['file' => 'unknown']);
        self::assertSame(400, $status);
        self::assertSame('The upload does not exist.', self::str($data, 'message'));

        $presign = $this->presign('document_file', 'a.txt', self::PNG, 'text/plain');
        $this->put($presign, self::PNG);
        [$status, $data] = $this->requestJson('POST', '/api/documents', ['file' => $presign['uploadId']]);
        self::assertSame(400, $status);
        self::assertStringContainsString('The mime type of the file is invalid ("image/png")', self::str($data, 'message'), 'Says why verification failed');
    }

    public function testApiRejectsAnUploadForAnotherProperty(): void
    {
        $token = $this->uploadText('a.txt', 'content');

        [$status, $data] = $this->requestJson('POST', '/api/documents', ['image' => $token]);

        self::assertSame(422, $status);
        self::assertSame('The upload was made for "document_file", it cannot be stored as "document_image".', self::str($data, 'message'));
        self::assertSame(0, $this->em()->getRepository(Document::class)->count());
        self::assertSame(UploadState::Verified, $this->freshState($token), 'Still claimable for its own property');
    }

    public function testApiClearsAndReplacesObjects(): void
    {
        $first = $this->uploadText('first.txt', 'first');
        $firstKey = self::keyOfUpload($first);
        [, $data] = $this->requestJson('POST', '/api/documents', ['file' => $first]);
        $id = $data['id'] ?? null;
        self::assertIsInt($id);

        $second = $this->uploadText('second.txt', 'second');
        $secondKey = self::keyOfUpload($second);
        [$status] = $this->requestJson('PATCH', '/api/documents/'.$id, ['file' => $second]);
        self::assertSame(200, $status);
        self::assertFalse($this->objectExists('test-private', $firstKey), 'Replaced object deleted');

        [$status, $data] = $this->requestJson('PATCH', '/api/documents/'.$id, ['file' => null]);
        self::assertSame(200, $status);
        self::assertArrayHasKey('file', $data);
        self::assertNull($data['file']);
        self::assertFalse($this->objectExists('test-private', $secondKey), 'Cleared object deleted');
    }

    private function resolve(string $token): StoredObject
    {
        return self::service(UploadManager::class)->resolveClaimable($token, 'document_file', $this->ownerOf($token));
    }

    private function freshState(string $token): ?UploadState
    {
        $this->em()->clear();

        return $this->state($token);
    }

    /**
     * @return string the document id
     */
    private function submit(string $document, string $token, bool $delete = false): string
    {
        $file = ['token' => $token] + ($delete ? ['delete' => '1'] : []);
        $this->client->request('POST', '/documents/'.$document, ['document' => ['file' => $file]]);
        self::assertResponseIsSuccessful();

        $id = $this->responseJson()['id'] ?? null;
        self::assertIsInt($id);

        return (string) $id;
    }
}
