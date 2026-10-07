<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\Integration;

use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vadage\PresignedUploaderBundle\Event\PreSignEvent;
use Vadage\PresignedUploaderBundle\Event\UploadClaimedEvent;
use Vadage\PresignedUploaderBundle\Event\UploadVerifiedEvent;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Messenger\ObjectCreated;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

#[Group('integration')]
final class UploadFlowTest extends IntegrationTestCase
{
    public function testValidationHappensBeforeSigning(): void
    {
        [$status, $data] = $this->postJson('/uploads/document_file', ['filename' => 'a.exe', 'size' => 2_000_000, 'mimeType' => 'application/x-msdownload']);

        self::assertSame(422, $status);
        self::assertStringContainsString('too large', self::str($data, 'violations', 0, 'message'));
    }

    public function testViolationsAreTranslated(): void
    {
        $server = ['HTTP_ACCEPT_LANGUAGE' => 'de'];

        [, $tooLarge] = $this->postJson('/uploads/document_file', ['filename' => 'a.txt', 'size' => 2_000_000, 'mimeType' => 'text/plain'], $server);
        [, $badType] = $this->postJson('/uploads/document_file', ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'not a type'], $server);

        self::assertStringStartsWith('Die Datei ist zu groß (2 MB).', self::str($tooLarge, 'violations', 0, 'message'));
        self::assertSame('Der Dateityp ist ungültig.', self::str($badType, 'violations', 0, 'message'));
    }

    public function testPreSignListenersCanDenyWithAStatusAndHeaders(): void
    {
        self::service(EventDispatcherInterface::class, 'event_dispatcher')->addListener(PreSignEvent::class, static function (PreSignEvent $event): void {
            $event->deny('Too many uploads, please try again later.', 429, ['Retry-After' => '30']);
        });

        [$status, $data] = $this->postJson('/uploads/document_file', ['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain']);

        self::assertSame(429, $status);
        self::assertSame('30', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('Too many uploads, please try again later.', self::str($data, 'message'));
    }

    public function testWidgetIsLabelledAndTranslated(): void
    {
        $this->client->request('GET', '/documents/new', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);

        self::assertSelectorExists('label[for="document_file_file"]');
        self::assertSelectorExists('input[type=file][id="document_file_file"]');
        self::assertSelectorExists('[data-vadage--presigned-uploader-bundle--upload-failed-message-value="Le téléversement a échoué, veuillez réessayer."]');
    }

    public function testUnknownMapping(): void
    {
        self::assertSame(404, $this->postJson('/uploads/nope', ['filename' => 'a.txt', 'size' => 1, 'mimeType' => 'text/plain'])[0]);
    }

    public function testChecksumIsRequiredWhenConfigured(): void
    {
        [$status, $data] = $this->postJson('/uploads/document_image', ['filename' => 'a.png', 'size' => 10, 'mimeType' => 'image/png']);

        self::assertSame(422, $status);
        self::assertSame('sha256', self::str($data, 'violations', 0, 'propertyPath'));
    }

    public function testStorageEnforcesSignedHeaders(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');

        self::assertSame(403, $this->put($presign, 'hello world'), 'Different size');
        self::assertSame(403, $this->put($presign, 'hello', ['Content-Type' => 'text/html']), 'Different type');
        self::assertSame(200, $this->put($presign, 'hello'));
        self::assertSame(412, $this->put($presign, 'HELLO'), 'The URL can only write once');
    }

    public function testFullFlowWithForm(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        self::assertSame(['Content-Type' => 'text/plain', 'If-None-Match' => '*'], $presign['headers']);

        self::assertSame(409, $this->verify($presign['uploadId'])[0], 'Nothing uploaded yet');
        self::assertSame(200, $this->put($presign, 'hello'));
        self::assertSame('verified', self::str($this->verify($presign['uploadId'])[1], 'state'));
        self::assertSame(200, $this->verify($presign['uploadId'])[0], 'Idempotent');

        $this->client->request('GET', '/documents/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="vadage--presigned-uploader-bundle--upload"][data-vadage--presigned-uploader-bundle--upload-presign-url-value="/uploads/document_file"]');
        self::assertSelectorExists('input[type=file][accept="application/pdf,text/plain,.pdf,.txt"]');

        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);
        self::assertResponseIsSuccessful();
        $document = $this->responseJson();

        self::assertSame('private', self::str($document, 'file', 'storage'));
        self::assertSame('notes.txt', self::str($document, 'file', 'originalName'));
        self::assertStringEndsWith('.txt', self::str($document, 'file', 'key'));
        self::assertTrue($this->objectExists('test-private', self::str($document, 'file', 'key')));

        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);
        self::assertResponseStatusCodeSame(422, 'An upload can only be claimed once');
    }

    public function testApiClaimsWithTheMappingOfTheProperty(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        [$status, $data] = $this->requestJson('POST', '/api/documents', ['image' => $presign['uploadId']]);
        self::assertSame(422, $status);
        self::assertSame('The upload was made for "document_file", it cannot be stored as "document_image".', self::str($data, 'message'));

        [$status, $data] = $this->requestJson('POST', '/api/documents', ['file' => $presign['uploadId']]);
        self::assertSame(200, $status);
        self::assertSame('notes.txt', self::str($data, 'file', 'originalName'));
        self::assertNull($this->state($presign['uploadId']), 'Claimed');
    }

    public function testClaimVerifiesUploadsThatWereNotVerifiedYet(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);

        self::assertResponseIsSuccessful();
        self::assertSame('notes.txt', self::str($this->responseJson(), 'file', 'originalName'));
    }

    public function testClaimShowsWhyAnUnverifiedFileIsRejected(): void
    {
        $html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
        $presign = $this->presign('document_file', 'notes.txt', $html, 'text/plain');
        $this->put($presign, $html);

        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('text/html', self::str($this->responseJson(), 'errors'));
        self::assertSame(UploadState::Rejected, $this->state($presign['uploadId']));
    }

    public function testClaimBeforeTheUploadFinishedFails(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');

        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(UploadState::Pending, $this->state($presign['uploadId']), 'Still claimable once uploaded');
    }

    public function testEventsAreDispatchedOncePerUpload(): void
    {
        $events = [];
        $dispatcher = self::service(EventDispatcherInterface::class, 'event_dispatcher');
        foreach ([UploadVerifiedEvent::class, UploadClaimedEvent::class] as $event) {
            $dispatcher->addListener($event, static function (object $e) use (&$events): void {
                $events[] = $e::class;
            });
        }

        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');
        $this->sendWebhook('test-private', self::keyOf($presign['url'], 'test-private'));
        $this->verify($presign['uploadId']);
        $this->client->request('POST', '/documents/new', ['document' => ['file' => ['token' => $presign['uploadId']]]]);
        self::assertResponseIsSuccessful();

        self::assertSame([UploadVerifiedEvent::class, UploadClaimedEvent::class], $events);
    }

    public function testObjectCreatedMessageVerifiesTheUpload(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        self::service(MessageBusInterface::class, 'messenger.default_bus')->dispatch(new ObjectCreated('test-private', self::keyOf($presign['url'], 'test-private')));

        self::assertSame(UploadState::Verified, $this->state($presign['uploadId']));
    }

    public function testStagedUploadIsPromotedOnClaim(): void
    {
        $presign = $this->presign('document_image', 'pixel.png', self::PNG, 'image/png', checksum: true);
        self::assertArrayHasKey('x-amz-checksum-sha256', $presign['headers']);
        self::assertStringContainsString('/test-quarantine/incoming/images/', $presign['url']);

        self::assertSame(200, $this->put($presign, self::PNG));
        self::assertSame(200, $this->verify($presign['uploadId'])[0]);

        $this->client->request('POST', '/documents/new', ['document' => ['image' => ['token' => $presign['uploadId']]]]);
        self::assertResponseIsSuccessful();
        $document = $this->responseJson();
        $key = self::str($document, 'image', 'key');

        self::assertSame('public', self::str($document, 'image', 'storage'));
        self::assertStringStartsWith('images/', $key);
        self::assertTrue($this->objectExists('test-public', $key), 'Promoted');
        self::assertFalse($this->objectExists('test-quarantine', 'incoming/'.$key), 'Staging object removed');
    }

    public function testStagingObjectRecreatedThroughItsUrlIsDeletedAgain(): void
    {
        $presign = $this->presign('document_image', 'pixel.png', self::PNG, 'image/png', checksum: true);
        $stagingKey = self::keyOf($presign['url'], 'test-quarantine');
        $this->put($presign, self::PNG);
        $this->verify($presign['uploadId']);

        $this->client->request('POST', '/documents/new', ['document' => ['image' => ['token' => $presign['uploadId']]]]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->objectExists('test-quarantine', $stagingKey));

        self::assertSame(200, $this->put($presign, self::PNG), 'The presigned URL is still valid');
        self::assertSame(1, $this->sweepAt('+1 minute')->deletedObjects);
        self::assertFalse($this->objectExists('test-quarantine', $stagingKey), 'Deleted again while the URL is valid');
        self::assertSame(1, $this->sweepAt('+6 minutes')->deletedObjects);
        self::assertSame(0, $this->sweepAt('+7 minutes')->deletedObjects, 'Tombstone removed once the URL expired');
    }

    public function testAnUploadCanOnlyBeClaimedOnce(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');
        $this->verify($presign['uploadId']);
        $manager = self::service(UploadManager::class);
        $owner = $this->ownerOf($presign['uploadId']);

        // Two requests submitting the same token resolve it before either claims.
        $first = $manager->resolveClaimable($presign['uploadId'], 'document_file', $owner);
        $second = $manager->resolveClaimable($presign['uploadId'], 'document_file', $owner);
        self::assertNotNull($manager->claim($first));

        $this->expectException(UploadNotClaimableException::class);
        $manager->claim($second);
    }

    public function testUncommittedClaimIsRevertedAfterItsLease(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');
        $this->verify($presign['uploadId']);
        $manager = self::service(UploadManager::class);
        $object = $manager->resolveClaimable($presign['uploadId'], 'document_file', $this->ownerOf($presign['uploadId']));

        self::assertNotNull($manager->claim($object));
        self::assertSame(UploadState::Claiming, $this->state($presign['uploadId']));
        self::assertSame(0, $this->sweepAt('+14 minutes')->revertedClaims);
        self::assertSame(1, $this->sweepAt('+16 minutes')->revertedClaims);
        self::assertSame(UploadState::Verified, $this->state($presign['uploadId']));
    }

    public function testStagedUploadIsStreamedIntoAFilesystemOnlyStorage(): void
    {
        $presign = $this->presign('document_archive', 'notes.txt', 'archived content', 'text/plain');
        self::assertStringContainsString('/test-quarantine/archive/', $presign['url']);
        $this->put($presign, 'archived content');

        $this->client->request('POST', '/documents/new', ['document' => ['archive' => ['token' => $presign['uploadId']]]]);

        self::assertResponseIsSuccessful();
        self::assertSame('archive', self::str($this->responseJson(), 'archive', 'storage'));
        $filesystem = self::service(Filesystem::class, 'test.archive_filesystem');
        self::assertSame('archived content', $filesystem->read(self::str($this->responseJson(), 'archive', 'key')));
        self::assertFalse($this->objectExists('test-quarantine', self::keyOf($presign['url'], 'test-quarantine')));
    }

    public function testSpoofedContentIsRejectedAndDeleted(): void
    {
        $html = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';
        $presign = $this->presign('document_image', 'pixel.png', $html, 'image/png', checksum: true);
        self::assertSame(200, $this->put($presign, $html));

        [$status, $data] = $this->verify($presign['uploadId']);

        self::assertSame(422, $status);
        self::assertSame('rejected', self::str($data, 'state'));
        self::assertStringContainsString('text/html', self::str($data, 'violations', 0, 'message'));
        self::assertFalse($this->objectExists('test-quarantine', self::keyOf($presign['url'], 'test-quarantine')));
    }

    /**
     * Storages ignoring the signed headers would let the browser store something other than what was presigned.
     */
    #[TestWith(['hello world', 'text/plain', 'The stored file size does not match the announced size.'])]
    #[TestWith(['hello', 'text/html', 'The stored content type does not match the announced content type.'])]
    public function testObjectNotMatchingThePresignedSizeOrTypeIsRejected(string $content, string $mimeType, string $message): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $key = self::keyOf($presign['url'], 'test-private');
        $this->s3()->putObject(['Bucket' => 'test-private', 'Key' => $key, 'Body' => $content, 'ContentType' => $mimeType])->resolve();

        [$status, $data] = $this->verify($presign['uploadId']);

        self::assertSame(422, $status);
        self::assertSame($message, self::str($data, 'violations', 0, 'message'));
        self::assertFalse($this->objectExists('test-private', $key));
    }

    public function testPresignRequiresAJsonBody(): void
    {
        $this->client->request('POST', '/uploads/document_file', ['filename' => 'a.txt', 'size' => '5', 'mimeType' => 'text/plain']);
        self::assertResponseStatusCodeSame(415);

        $this->client->request('POST', '/uploads/document_file', server: ['CONTENT_TYPE' => 'application/json'], content: '{"filename":');
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[TestWith([['size' => 5, 'mimeType' => 'text/plain']])]
    #[TestWith([['filename' => 'a.txt', 'size' => '5', 'mimeType' => 'text/plain']])]
    #[TestWith([['filename' => 'a.txt', 'size' => 5]])]
    #[TestWith([['filename' => 'a.txt', 'size' => 5, 'mimeType' => 'text/plain', 'sha256' => 123]])]
    public function testPresignRejectsMalformedDescriptors(array $body): void
    {
        self::assertSame(400, $this->postJson('/uploads/document_file', $body)[0]);
    }

    #[TestWith(['', 5, 'filename', 'The file name is invalid.'])]
    #[TestWith(['   ', 5, 'filename', 'The file name is invalid.'])]
    #[TestWith(["a\nb.txt", 5, 'filename', 'The file name is invalid.'])]
    #[TestWith(['a.txt', -1, 'size', 'The file size is invalid.'])]
    public function testPresignRejectsInvalidDescriptors(string $filename, int $size, string $propertyPath, string $message): void
    {
        [$status, $data] = $this->postJson('/uploads/document_file', ['filename' => $filename, 'size' => $size, 'mimeType' => 'text/plain']);

        self::assertSame(422, $status);
        $violations = self::at($data, 'violations');
        self::assertIsArray($violations);
        self::assertContains(['propertyPath' => $propertyPath, 'message' => $message], $violations);
    }

    public function testPresignRejectsFileNamesLongerThan255Characters(): void
    {
        [$status, $data] = $this->postJson('/uploads/document_file', ['filename' => str_repeat('a', 252).'.txt', 'size' => 5, 'mimeType' => 'text/plain']);

        self::assertSame(422, $status);
        $violations = self::at($data, 'violations');
        self::assertIsArray($violations);
        self::assertContains(['propertyPath' => 'filename', 'message' => 'The file name is invalid.'], $violations);
    }

    public function testOnlyTheOwnerCanComplete(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        $this->client->getCookieJar()->clear();

        self::assertSame(404, $this->verify($presign['uploadId'])[0]);
        self::assertSame(404, $this->verify('invalid.token')[0]);
    }

    /**
     * @param 'r2'|'s3' $provider
     */
    #[TestWith(['r2'])]
    #[TestWith(['s3'])]
    public function testWebhookCompletesUpload(string $provider): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');

        $this->sendWebhook('test-private', self::keyOf($presign['url'], 'test-private'), $provider);
        self::assertResponseIsSuccessful();

        self::assertSame(UploadState::Verified, self::service(UploadManager::class)->findByToken($presign['uploadId'])?->getState());
        self::assertSame('verified', self::str($this->verify($presign['uploadId'])[1], 'state'), 'Browser callback after the webhook is a no-op');
    }

    public function testCleanupDeletesUnclaimedUploads(): void
    {
        $presign = $this->presign('document_file', 'notes.txt', 'hello', 'text/plain');
        $this->put($presign, 'hello');
        $this->verify($presign['uploadId']);
        $key = self::keyOf($presign['url'], 'test-private');
        self::assertTrue($this->objectExists('test-private', $key));

        Clock::set(new MockClock('+2 days'));
        try {
            $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('vadage:presigned-uploader:cleanup'));
            $tester->execute([]);
        } finally {
            Clock::set(new NativeClock());
        }

        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->objectExists('test-private', $key));
        self::assertNull(self::service(UploadManager::class)->findByToken($presign['uploadId']));
    }
}
