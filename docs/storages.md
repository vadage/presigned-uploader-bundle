# Storages, staging and Flysystem

Say your application keeps two kinds of files: invoices, which only their owner may download, and avatars,
which everyone sees and which a CDN should serve. Invoices go to a private bucket and are read through
short-lived presigned URLs. Avatars go to a public bucket, but a public bucket serves a file the moment it is
uploaded, before the bundle has verified it. So avatars are uploaded to a private quarantine bucket first and
only copied to the public one when the form is saved.

Each of these buckets is a storage, configured once and referenced by name from your mappings.

## Storage options

Storages are configured under `vadage_presigned_uploader.storages` and referenced by name from
`#[UploadableField(storage: ...)]`.

| Option                | Default  | Description                                                                                                                                                        |
|-----------------------|----------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `client`              | `null`   | Service id of an `AsyncAws\S3\S3Client`. Required, with `bucket`, for storages that receive uploads                                                                |
| `bucket`              | `null`   | Bucket name                                                                                                                                                        |
| `filesystem`          | `null`   | Service id of a Flysystem `FilesystemOperator`, see [Flysystem storages](#flysystem-storages)                                                                      |
| `public_url`          | `null`   | Base URL for public reads, e.g. a CDN or an R2 custom domain. `null` reads through presigned `GET` URLs                                                            |
| `conditional_put`     | `true`   | Sign `If-None-Match: *`, so a presigned URL can only write once                                                                                                    |
| `credential_provider` | `null`   | Service id of an AsyncAws `CredentialProvider`; defaults to the async-aws default chain, which uses the client's configuration and the usual environment variables |
| `staging`             | disabled | See [Staging](#staging)                                                                                                                                            |

A storage needs a `client` and `bucket`, a `filesystem`, or both.

## Providers

The bundle signs requests for any S3-compatible API through the async-aws client's endpoint, region and
addressing style. Configure one client per account and endpoint, then one storage per bucket.

On AWS, the credentials need `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject` and `s3:ListBucket` on the
buckets they upload to and read from (copying a staged object needs `s3:GetObject` on the staging bucket and
`s3:PutObject` on the target). Without `s3:ListBucket`, AWS answers `403`
instead of `404` for objects that do not exist yet, and verifying an upload that has not finished fails with an
error instead of "not uploaded yet".

```yaml
# config/packages/async_aws.yaml
async_aws:
    clients:
        aws:                                # AWS S3
            type: s3
            config:
                region: eu-central-1
                accessKeyId: '%env(AWS_ACCESS_KEY_ID)%'
                accessKeySecret: '%env(AWS_SECRET_ACCESS_KEY)%'
        r2:                                 # Cloudflare R2
            type: s3
            config:
                endpoint: 'https://%env(R2_ACCOUNT_ID)%.r2.cloudflarestorage.com'
                region: auto
                pathStyleEndpoint: true
                accessKeyId: '%env(R2_ACCESS_KEY_ID)%'
                accessKeySecret: '%env(R2_SECRET_ACCESS_KEY)%'
        seaweedfs:                          # self-hosted, e.g. SeaweedFS
            type: s3
            config:
                endpoint: 'https://s3.example.com'
                region: us-east-1
                pathStyleEndpoint: true
                accessKeyId: '%env(S3_ACCESS_KEY_ID)%'
                accessKeySecret: '%env(S3_SECRET_ACCESS_KEY)%'
```

```yaml
# config/packages/vadage_presigned_uploader.yaml
vadage_presigned_uploader:
    storages:
        documents:
            client: async_aws.client.aws
            bucket: my-app-documents
        media:
            client: async_aws.client.r2
            bucket: media
            public_url: 'https://media.example.com'
```

The endpoint must be reachable from your users' browsers: the presigned URL points to it.

## Public and private storages

A storage with `public_url` is meant for a bucket that is publicly readable (e.g. through a CDN or custom
domain); `url()` and the form widget then link to `public_url` + key. Without `public_url`, files are read
through presigned `GET` URLs that expire after five minutes by default, so the bucket can stay private.

Prefer private storages. Public buckets serve files exactly as they were uploaded, see
[Security](security.md#public-buckets).

## Staging

With staging, the browser uploads to another storage (or another prefix) first. The object is promoted to its
final location when the upload is claimed, and the staging object is deleted once the claim is committed.

```yaml
vadage_presigned_uploader:
    storages:
        media:                          # public bucket
            client: async_aws.client.r2
            bucket: media
            public_url: 'https://media.example.com'
            staging:
                storage: quarantine     # the storage the browser uploads to
                prefix: incoming/       # default
        quarantine:                     # private bucket
            client: async_aws.client.r2
            bucket: quarantine
```

| Option             | Default        | Description                                                                          |
|--------------------|----------------|--------------------------------------------------------------------------------------|
| `staging.storage`  | required       | Storage the browser uploads to. It needs a `client` and must not have staging itself |
| `staging.prefix`   | `incoming/`    | Prepended to the final key to get the staging key                                    |
| `staging.promoter` | `CopyPromoter` | Service id of a [`PromoterInterface`](events-and-extension-points.md#promoter)       |

Setting any staging option enables staging. A storage can stage into itself when the prefix is not empty, but
that only makes sense for private storages. Mappings inherit the staging of their storage; disable it per
mapping with `#[UploadableField(staging: false)]`, or require it with `staging: true`.

### Staging or not?

Without staging, the object is at its final key immediately. On a **public** bucket, it is reachable before it
is verified and claimed, and the uploader knows its URL (it is in the presigned URL). Use staging for public,
user generated content from untrusted users; prefer private storages otherwise.

R2 public access is configured per bucket, so staging on R2 needs a separate bucket.

### Promotion

The default `CopyPromoter` copies the object server-side when both storages share a client (or a filesystem),
and streams it through the application otherwise. Promotion happens during the claim, which with Doctrine runs
inside `flush()`: keep that in mind for large files that are streamed. Implement your own
[promoter](events-and-extension-points.md#promoter) to change files on the way, e.g. to re-encode images.

## Flysystem storages

A storage with a `filesystem` reads, writes and deletes objects through any Flysystem `FilesystemOperator`,
e.g. one configured with [league/flysystem-bundle](https://github.com/thephpleague/flysystem-bundle):

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        archive.storage:
            adapter: 'local'
            options:
                directory: '%kernel.project_dir%/var/archive'

# config/packages/vadage_presigned_uploader.yaml
vadage_presigned_uploader:
    storages:
        quarantine:
            client: async_aws.client.r2
            bucket: quarantine
        archive:                        # no S3 client: uploads are staged and promoted into it
            filesystem: archive.storage
            staging: { storage: quarantine, prefix: archive/ }
```

Uploads always go to S3-compatible storages. A storage with only a `filesystem` cannot receive uploads directly,
so mappings on it need staging in a storage with a `client`. When a storage has both a `client` and a
`filesystem`, the filesystem must address the same bucket without a path prefix of its own.

URLs for objects in a filesystem-only storage come from `public_url`, or from the filesystem's
`temporaryUrl()`, which not every adapter supports. Set `public_url`, or the form option `download_uri: false`,
for adapters without temporary URLs.

## Storage compatibility

async-aws' presigner leaves `Content-Type`, `Content-Length` and conditional headers out of the signature, so
the bundle signs `PUT` requests itself (AWS Signature Version 4, query string). The integration tests verify
against SeaweedFS that the storage rejects a different size, content type and checksum, and a second write.
Run the same checks against your provider before relying on them.

Verification checks size, content type and checksum again after the upload, which guards against storages that
ignore signed headers. Single-write protection, however, depends on the storage honouring `If-None-Match: *`.
Disable `conditional_put` for storages that do not support it. Without it, a presigned URL can overwrite the
verified object until it expires, so mappings uploading to such storages must set `checksum: true`; the
container does not compile otherwise.

Uploads use a single `PUT`, which S3 limits to 5 GiB.
