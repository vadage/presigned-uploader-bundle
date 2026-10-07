# APIs and API Platform

APIs write `StoredObject` properties with upload ids, through the Serializer component. This works with
API Platform (REST and GraphQL) and `#[MapRequestPayload]`.

## Upload ids in, stored objects out

With symfony/serializer installed, the bundle registers a normalizer for `StoredObject`. A client uploads the
file as usual and then sends the upload id in place of the object:

1. `POST /uploads/{mapping}` returns the upload id and the presigned URL, see
   [HTTP endpoints](javascript-client.md#http-endpoints).
2. The client sends the file to the presigned URL.
3. The client creates or updates your resource with the upload id:

```http
PATCH /api/documents/42
Content-Type: application/merge-patch+json

{ "file": "2bYkq8N7vX3cTQh1kZp6Rw.Z0FhcXo1..." }
```

Writing a mapped property accepts:

- an upload id (a string): the normalizer resolves it for the current owner and verifies the upload if that did
  not happen yet. Your entity receives a `StoredObject`, which the [Doctrine flush](upload-lifecycle.md#claiming-with-doctrine)
  claims like one from the form type;
- `null`: clears the property and deletes the previous object (with `deleteOnReplace`).

Leave the property out of the request to keep the current object. Anything else is rejected, in particular an
object with a storage and key: it would let a client point the property at any object in your storages
([why](security.md#other-notes)).

Reading returns the object with a URL, from `public_url` or presigned for five minutes:

```json
{
    "file": {
        "url": "https://storage.example.com/documents/0193...txt?X-Amz-Algorithm=...",
        "size": 48213,
        "mimeType": "application/pdf",
        "originalName": "contract.pdf",
        "sha256": null,
        "uploadedAt": "2026-10-06T14:03:11+00:00"
    }
}
```

The storage name and key are internal and not part of the output. To change the format, decorate the
`vadage_presigned_uploader.serializer.normalizer` service.

The serializer needs to know the property's type: declare it (`?StoredObject`) and keep the PropertyInfo
component enabled, which it is in applications with symfony/serializer-pack or API Platform.

### Errors

The value is rejected while deserializing when it is not a string, or when the upload is unknown, belongs to
someone else, has expired, has not finished or was rejected. The error names the property and says why (for a
rejected file, with the verification violations):

- API Platform answers `400` with the reason as `detail`, or `422` with a violation per property when
  `collect_denormalization_errors` is enabled;
- `#[MapRequestPayload]` answers `422` with a violation per property, with the reason in its `hint` parameter.

An upload is presigned for one mapping, and the serializer cannot tell which property it is writing to.
Claiming checks that the upload is stored in the property of its mapping, and fails the flush
otherwise. Only a client that mixes up its upload ids gets there; map the exception to a status code if it
should not end as a server error, e.g. with API Platform:

```php
#[ApiResource(exceptionToStatus: [UploadNotClaimableException::class => 422])]
```

### Owners and CSRF

The request that sends the upload id has to come from the owner of the upload: the same authenticated user, or
the same session for anonymous users (see [Owners](security.md#owners)). Stateless APIs for anonymous clients
have no session; [replace the owner resolver](events-and-extension-points.md#owner-resolver) for them.

Clients that authenticate with tokens instead of cookies cannot send a CSRF token to the upload endpoints;
[turn the check off](security.md#csrf-protection) for them.

## API Platform

Expose the property in both directions, like any other property:

```php
use ApiPlatform\Metadata\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Doctrine\StoredObjectType;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

#[ApiResource(
    normalizationContext: ['groups' => ['document:read']],
    denormalizationContext: ['groups' => ['document:write']],
    exceptionToStatus: [UploadNotClaimableException::class => 422],
)]
#[Uploadable]
#[ORM\Entity]
class Document
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['document:read'])]
    public ?int $id = null;

    #[UploadableField(name: 'document_file', storage: 'documents')]
    #[PresignedFile(maxSize: '20M', mimeTypes: ['application/pdf'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    #[Groups(['document:read', 'document:write'])]
    public ?StoredObject $file = null;
}
```

API Platform's state processor persists and flushes the entity, which claims the upload in the same
transaction. `#[PresignedFile]` belongs to the `presign` validation group, so it does not run when API
Platform validates the resource.

API Platform 4.4 and 5 are supported; GraphQL requires API Platform 5.

### OpenAPI

The bundle documents `StoredObject` properties as they are read and written: a string (the upload id) in
request bodies, an object with `url`, `size`, `mimeType`, `originalName`, `sha256` and `uploadedAt` in
responses. A schema you declare with `#[ApiProperty(schema: ...)]` takes precedence.

The upload endpoints themselves are no API Platform operations and do not appear in the OpenAPI documentation;
see [HTTP endpoints](javascript-client.md#http-endpoints).

### GraphQL

Mutations take the upload id as a `String`, queries return a `StoredObject`:

```graphql
mutation {
    createDocument(input: { file: "2bYkq8N7vX3cTQh1kZp6Rw.Z0FhcXo1..." }) {
        document {
            id
            file { url size mimeType originalName sha256 uploadedAt }
        }
    }
}
```

`size` is a `Float`: GraphQL's `Int` has 32 bits, files can be larger than 2 GiB. A `null` file clears the
property, and errors appear in the response's `errors` like other denormalization errors. Clients upload the
file through the [HTTP endpoints](javascript-client.md#http-endpoints) before the mutation.

## Without Doctrine

For DTOs, e.g. with `#[MapRequestPayload]`, claim the object yourself as described in
[the manual API](upload-lifecycle.md#without-doctrine-dtos). Pass the mapping of the property to `claim()`: the
serializer resolved the upload for whatever mapping it was made for.

```php
$claim = $this->uploads->claim($input->avatar, 'user_avatar');
```
