# Mapping and validation

## Mapping a property

A mapped property holds a `?StoredObject`. Mark the class with `#[Uploadable]` and the property with
`#[UploadableField]`:

```php
use Doctrine\ORM\Mapping as ORM;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Doctrine\StoredObjectType;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

#[Uploadable]
#[ORM\Entity]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[UploadableField(name: 'document_file', storage: 'documents', uploadTtl: 600)]
    #[PresignedFile(maxSize: '20M', mimeTypes: ['application/pdf', 'text/plain'], extensions: ['pdf', 'txt'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $file = null;

    #[UploadableField(name: 'document_image', storage: 'media', prefix: 'images/', checksum: true)]
    #[PresignedFile(maxSize: '5M', mimeTypes: ['image/png'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $image = null;
}
```

`StoredObjectType::NAME` (`presigned_stored_object`) stores the object as JSON (JSONB on PostgreSQL). The type is
registered automatically when DoctrineBundle is installed. Mappings work on DTOs and other non-Doctrine classes
too; see [the manual API](upload-lifecycle.md#without-doctrine-dtos) for claiming uploads there.

### Discovery

`#[Uploadable]` classes registered by your service configuration (e.g. the default
`App\: resource: '../src/'`) are discovered automatically, including mappings declared on parent classes.
Classes excluded from service registration must be listed explicitly:

```yaml
vadage_presigned_uploader:
    mapped_classes:
        - App\Upload\AvatarUpload
```

Mappings are checked when the container is compiled: unknown storages, duplicate or invalid names, a missing
maximum size, invalid namers or promoters, and similar mistakes fail the build with a message that names the
property.

### `#[UploadableField]` options

| Option            | Default          | Description                                                                                                               |
|-------------------|------------------|---------------------------------------------------------------------------------------------------------------------------|
| `name`            | required         | Public, stable identifier used in the presign URL (`/uploads/{name}`). Letters, digits, `_`, `.` and `-`                  |
| `storage`         | required         | A storage configured under `vadage_presigned_uploader.storages`                                                           |
| `namer`           | `defaults.namer` | Service id of a [`NamerInterface`](events-and-extension-points.md#namer)                                                  |
| `prefix`          | `''`             | Key prefix passed to the namer, e.g. `avatars/`                                                                           |
| `checksum`        | `false`          | Require a SHA-256 checksum from the client; the storage verifies the bytes against it                                     |
| `staging`         | `null`           | `null` inherits the storage's [staging](storages.md#staging), `false` disables it, `true` requires it                     |
| `sniffContent`    | `null`           | `null` inherits `defaults.sniff_content`, `false` disables the magic-byte check                                           |
| `uploadTtl`       | `null`           | Seconds the presigned `PUT` stays valid, `null` inherits `defaults.upload_ttl`. Must be shorter than `defaults.claim_ttl` |
| `deleteOnRemove`  | `true`           | Delete the object when the owning entity is removed                                                                       |
| `deleteOnReplace` | `true`           | Delete the previous object when the value is replaced or cleared                                                          |
| `uploads`         | `true`           | `false` for properties only your application writes: no presigning and no maximum size, objects are still deleted         |
| `security`        | `null`           | Expression that has to be granted before presigning, see [Security](security.md#authorization)                            |

`deleteOnRemove` and `deleteOnReplace` apply to Doctrine entities; see [Upload lifecycle](upload-lifecycle.md).

## Validation

### `#[PresignedFile]`

| Option                                                                    | Default       | Description                                                                                              |
|---------------------------------------------------------------------------|---------------|----------------------------------------------------------------------------------------------------------|
| `maxSize`                                                                 | `null`        | Bytes, or a number with `k`, `M`, `G` (powers of 1000) or `Ki`, `Mi`, `Gi` (powers of 1024), e.g. `'5M'` |
| `mimeTypes`                                                               | `[]`          | Allowed MIME types; wildcards like `image/*` are supported                                               |
| `extensions`                                                              | `[]`          | Allowed extensions (without dot) of the client's file name                                               |
| `maxSizeMessage`, `emptyMessage`, `mimeTypesMessage`, `extensionsMessage` |               | Custom messages                                                                                          |
| `groups`                                                                  | `['presign']` | Validation groups                                                                                        |

The constraint rejects empty files, and it is repeatable.

`#[PresignedFile]` belongs to the `presign` group only, so it does not interfere with your regular validation.
It runs twice:

1. against what the client announces, before a URL is signed;
2. against what is actually stored, when the upload is verified, with the MIME type sniffed from the stored
   bytes instead of the announced one.

Any other constraint you add to the property in the `presign` group runs at both points as well. Its validator
receives an `UploadDescriptor` (`filename`, `size`, `mimeType`, `sha256`).

On top of the constraints, the bundle checks that the file name is valid, the size is within the mapping's
maximum and at most 5 GiB (the largest single S3 `PUT`), and the MIME type is well-formed.

### Maximum size

Every mapping needs an upper bound; otherwise anyone allowed to presign could upload up to 5 GiB. Set it with
`#[PresignedFile(maxSize: ...)]` on the property. `defaults.max_size` covers the mappings without one, e.g. when
your constraints are mapped in YAML or XML, which the bundle cannot see when the container is built. The
container does not compile when a mapping has neither.

### Content sniffing

Verification reads the first 4 KiB of the stored object and detects its MIME type with libmagic
(`ext-fileinfo`). The `presign` constraints are validated again with the detected type, so the content has to be
one of the allowed types, e.g. an HTML file announced as `image/png` is rejected. The detected type does not have
to equal the announced one: a text file announced as `application/pdf` passes when `text/plain` is allowed too.
The stored object keeps the announced type (`getMimeType()`), which the storage also serves as `Content-Type`.
Allow only the types you are prepared to serve.

When libmagic recognizes nothing in those bytes (it reports `application/octet-stream`), the sniff is
inconclusive and the announced type is validated instead. This happens for very small files, formats libmagic
does not know, and formats it can only tell apart by the end of the file, e.g. JARs that start with
`META-INF/MANIFEST.MF` (older libmagic versions report those as `application/zip`, so allow it for JARs).
Content libmagic does recognize, such as HTML, SVG or executables, always has to be allowed. Sniffing only looks
at the first bytes, so it does not prove a file is valid: anything starting with the PNG signature is
`image/png`. Disable sniffing per mapping with `sniffContent: false`, or for all mappings with
`defaults.sniff_content: false`.

## Reading stored objects

`StoredObject` exposes `getStorage()`, `getKey()`, `getSize()`, `getMimeType()`, `getOriginalName()`,
`getSha256()` and `getUploadedAt()`. To link to the file, ask the storage for a URL:

```php
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;

public function __construct(private StorageRegistry $storages)
{
}

public function avatarUrl(User $user): ?string
{
    $avatar = $user->getAvatar();

    return null === $avatar ? null : $this->storages->objects($avatar->getStorage())->url($avatar->getKey());
}
```

`url()` returns `public_url` + key when the storage has a public URL. Otherwise it returns a presigned `GET`
URL (or the filesystem's temporary URL for [Flysystem storages](storages.md#flysystem-storages)) valid for five
minutes; pass a `\DateTimeImmutable` as second argument for another expiry. `getOriginalName()` is
client input: escape it when you display it and don't use it as a file name on your own systems.

`StoredObject::toArray()` and `StoredObject::fromArray()` convert the object for storage outside of Doctrine.
