# Events and extension points

## Events

Events are dispatched with their class name as event name, so a listener only needs the type hint:

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vadage\PresignedUploaderBundle\Event\UploadVerifiedEvent;

#[AsEventListener]
final readonly class StartProcessing
{
    public function __invoke(UploadVerifiedEvent $event): void
    {
        if ('document_file' === $event->mapping->name) {
            // e.g. dispatch a message that scans $event->upload->getKey() in $event->upload->getStorage()
        }
    }
}
```

All events are in the `Vadage\PresignedUploaderBundle\Event` namespace.

### `PreSignEvent`

Dispatched after the announced file passed validation, before the URL is signed. Use it for authorization,
quotas or rate limiting ([example](security.md#quotas-and-rate-limits)).

| Property / method                                                                                 | Description                                                                                          |
|---------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------|
| `$mapping`                                                                                        | The `UploadMapping` (`name`, `class`, `property`, `storage`, ...)                                    |
| `$descriptor`                                                                                     | The announced `UploadDescriptor` (`filename`, `size`, `mimeType`, `sha256`)                          |
| `$ownerId`                                                                                        | Who uploads, e.g. `user:alice`, or `session:` and a hash of the session id                           |
| `deny(string $reason = 'The upload is not allowed.', int $statusCode = 403, array $headers = [])` | Refuses the upload; the presign endpoint answers with this status, headers and `{"message": reason}` |

### `UploadVerifiedEvent`

The object exists in the storage and passed verification. Dispatched once per upload, whether the claim, the
verify call or a storage event verified it first. Properties: `$upload` (`PendingUpload`), `$mapping`.

### `UploadRejectedEvent`

The stored object did not match what was validated before signing; it has been deleted. Properties: `$upload`,
`$mapping`, `$violations` (`ConstraintViolationListInterface`).

### `UploadClaimedEvent`

The upload was attached to an entity or DTO and the object is at its final location. Dispatched after the
Doctrine flush, or by `UploadManager::commitClaim()`. When the flush runs inside an outer transaction, that
transaction may still roll back. Properties: `$upload`, `$mapping`, `$object` (`StoredObject`).

`PendingUpload` gives access to the upload's `getStorage()`/`getKey()` (where the browser uploaded to),
`getTargetStorage()`/`getTargetKey()` (the final location), `getFilename()`, `getSize()`, `getMimeType()`,
`getOwnerId()`, `getState()` and its timestamps.

## Namer

A namer generates the storage key of an upload. The default `UuidNamer` returns
`<prefix><random UUID>.<extension>`, with the extension derived from the validated MIME type.

```php
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Uuid;
use Vadage\PresignedUploaderBundle\Naming\NamerInterface;
use Vadage\PresignedUploaderBundle\Naming\UploadContext;

final readonly class DatedNamer implements NamerInterface
{
    public function name(UploadContext $context): string
    {
        $extension = MimeTypes::getDefault()->getExtensions($context->descriptor->mimeType)[0] ?? 'bin';

        return $context->mapping->prefix.date('Y/m/').Uuid::v4()->toRfc4122().'.'.$extension;
    }
}
```

`UploadContext` holds the `$mapping`, the announced `$descriptor` and the `$ownerId`. The key must include the
mapping's prefix and must be unique; never derive it from the client's file name alone.

Register the namer as a service (autowired application services are) and reference its service id, for all
mappings or per mapping:

```yaml
vadage_presigned_uploader:
    defaults:
        namer: App\Upload\DatedNamer
```

```php
#[UploadableField(name: 'invoice_pdf', storage: 'documents', namer: DatedNamer::class)]
```

## Promoter

A promoter moves a staged upload to its final location when it is claimed. It reads `$upload->getKey()` from
the staging store and writes `$upload->getTargetKey()` to the target store; the bundle deletes the staging object
once the claim is committed. The default `CopyPromoter` copies the object unchanged.

```php
use Vadage\PresignedUploaderBundle\Model\PendingUpload;
use Vadage\PresignedUploaderBundle\Staging\PromoterInterface;
use Vadage\PresignedUploaderBundle\Storage\ObjectStoreInterface;

final readonly class SanitizingPromoter implements PromoterInterface
{
    public function __construct(private MetadataStripper $stripper) // your own service
    {
    }

    public function promote(PendingUpload $upload, ObjectStoreInterface $staging, ObjectStoreInterface $target): void
    {
        $source = $staging->readStream($upload->getKey());
        try {
            $target->writeStream($upload->getTargetKey(), $this->stripper->strip($source), $upload->getMimeType());
        } finally {
            fclose($source);
        }
    }
}
```

```yaml
vadage_presigned_uploader:
    storages:
        media:
            # ...
            staging:
                storage: quarantine
                promoter: App\Upload\SanitizingPromoter
```

The `StoredObject` keeps the size and MIME type of the upload, so keep the format when you change the content.

### `ObjectStoreInterface`

Promoters receive both storages as `ObjectStoreInterface`, which every configured storage provides:

| Method                                                                         | Description                                                                                                        |
|--------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------|
| `getName(): string`                                                            | The storage's name in the configuration                                                                            |
| `readStream(string $key)`                                                      | Returns a readable stream resource                                                                                 |
| `writeStream(string $key, $contents, string $mimeType): void`                  | Writes a stream resource                                                                                           |
| `delete(string $key): void`                                                    | Deletes an object; a missing object is not an error                                                                |
| `copyFrom(ObjectStoreInterface $source, string $sourceKey, string $key): bool` | Copies server-side when both stores share an account or filesystem; returns `false` (and copies nothing) otherwise |
| `url(string $key, ?\DateTimeImmutable $expiresAt = null): string`              | The public URL, or a temporary one                                                                                 |

Get the store of a storage with `StorageRegistry::objects($name)`. Storages are backed by the S3 client or, with
`filesystem`, by Flysystem; for other backends, use a Flysystem adapter.

## Owner resolver

The owner resolver identifies who an upload belongs to (see [Owners](security.md#owners)). To change it, e.g.
to include a tenant, decorate the `vadage_presigned_uploader.owner_resolver` service:

```php
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;

#[AsDecorator('vadage_presigned_uploader.owner_resolver')]
final readonly class TenantOwnerResolver implements OwnerResolverInterface
{
    public function __construct(
        #[AutowireDecorated] private OwnerResolverInterface $inner,
        private TenantContext $tenants, // your own service
    ) {
    }

    public function resolve(): string
    {
        return 'tenant:'.$this->tenants->currentId().'|'.$this->inner->resolve();
    }
}
```

Owner ids are stored with the upload (up to 255 characters).

## Pending upload repository

Pending uploads and tombstones are stored with Doctrine by default. To store them elsewhere, implement
`Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface` and configure its service id:

```yaml
vadage_presigned_uploader:
    pending_upload:
        repository: App\Upload\RedisPendingUploadRepository
```

Read the interface's doc comments carefully: `transition()` and `revertStaleClaim()` must be atomic, and a stale
claim must not be reverted while the transaction that claims it is still running. Without the Doctrine
repository, claims are not tied to your transactions; use the [manual API](upload-lifecycle.md#without-doctrine-dtos).

## Services

These services can be autowired by their class or interface:

| Service                  | Use                                                                                                          |
|--------------------------|--------------------------------------------------------------------------------------------------------------|
| `UploadManager`          | `presign()`, `resolveClaimable()`, `claim()`, `commitClaim()`, `abandonClaim()`, `delete()`, `findByToken()` |
| `UploadVerifier`         | `verify(PendingUpload)`, `verifyLocation(string $bucket, string $key)`                                       |
| `UploadSweeper`          | `sweep(int $limit = 500)`, what the cleanup command runs                                                     |
| `StorageRegistry`        | `objects(string $storage)`, the `ObjectStoreInterface` of a storage                                          |
| `MappingRegistry`        | `get()`, `has()`, `find(string $class, string $property)`, `forClass()`                                      |
| `OwnerResolverInterface` | `resolve()`, the current owner id                                                                            |

`UploadManager::presign()` signs an upload without the HTTP endpoint, e.g. for your own API:

```php
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;

$result = $uploadManager->presign('user_avatar', new UploadDescriptor('avatar.png', 48213, 'image/png'), $ownerResolver->resolve());
// $result->token: the upload id; $result->request: method, url, headers, expiresAt
```

It throws `UploadValidationException` (with `$violations`) or `UploadDeniedException` (with `$statusCode` and
`$headers`). All exceptions of the bundle implement `Vadage\PresignedUploaderBundle\Exception\ExceptionInterface`.
