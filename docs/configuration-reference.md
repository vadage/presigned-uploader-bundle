# Configuration reference

Generated with `php bin/console config:dump-reference vadage_presigned_uploader`. The options are explained in
[Storages](storages.md), [Mapping and validation](mapping-and-validation.md),
[Upload lifecycle](upload-lifecycle.md), [Storage events](storage-events.md) and [Security](security.md). Validation rules that the
dump does not show:

- A storage needs a `client` and `bucket` (configured together), a `filesystem`, or both.
- `defaults.upload_ttl` is between 1 and 604800 seconds (7 days); `defaults.claim_ttl` and
  `defaults.claim_lease` are at least 60 seconds, and `claim_ttl` must be longer than `upload_ttl`.
- `defaults.max_size` is a size of at most 5 GiB, e.g. `50M` or `1Gi`.
- Every mapping needs a maximum size, from `#[PresignedFile(maxSize: ...)]` or `defaults.max_size`.

```yaml
# Default configuration for extension with alias: "vadage_presigned_uploader"
vadage_presigned_uploader:

    # Named storages, referenced by #[UploadableField(storage: ...)]
    storages:

        # Prototype
        name:

            # Service id of an AsyncAws\S3\S3Client; required for storages that receive presigned uploads
            client:               null
            bucket:               null

            # Service id of a Flysystem FilesystemOperator for reading, writing and deleting objects instead of the S3 client; a storage with only a filesystem can be a staging target, but receives no uploads
            filesystem:           null

            # Base URL for public reads (e.g. a CDN or R2 custom domain); null reads via presigned GET (or the filesystem's temporary URLs)
            public_url:           null

            # Sign "If-None-Match: *" so a presigned URL can only write once; disable for storages not supporting it
            conditional_put:      true

            # Service id of an AsyncAws CredentialProvider; defaults to the async-aws default chain (client configuration, env vars, ...)
            credential_provider:  null

            # Upload to another storage first and promote on claim
            staging:
                enabled:              false

                # Storage the browser uploads to
                storage:              null
                prefix:               incoming/

                # Service id of a Vadage\PresignedUploaderBundle\Staging\PromoterInterface
                promoter:             Vadage\PresignedUploaderBundle\Staging\CopyPromoter
    defaults:

        # Seconds a presigned PUT stays valid (SigV4 allows at most 7 days)
        upload_ttl:           300

        # Seconds an upload can be claimed before it is cleaned up
        claim_ttl:            86400

        # Seconds after which the cleanup command reverts a claim whose transaction never committed
        claim_lease:          900

        # Service id of a Vadage\PresignedUploaderBundle\Naming\NamerInterface
        namer:                Vadage\PresignedUploaderBundle\Naming\UuidNamer

        # Validate the MIME type detected from the stored bytes instead of the declared one
        sniff_content:        true

        # Largest upload for mappings without #[PresignedFile(maxSize: ...)], e.g. "50M"; required if there are such mappings
        max_size:             null

    # Require a CSRF token on the upload endpoints when symfony/security-csrf is installed; disable it for clients that authenticate with tokens instead of cookies
    csrf_protection:      true

    # Classes with #[Uploadable] that are not discovered automatically (e.g. excluded from service registration)
    mapped_classes:       []
    pending_upload:

        # "doctrine" or the service id of a Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface
        repository:           doctrine

        # Entity manager the bundle's entities are mapped in; defaults to the default entity manager. Use the one your uploadable entities live in, so claims share their transaction
        entity_manager:       null

    # Storage event webhooks, enabled per provider by setting a secret (requires symfony/webhook, symfony/remote-event and symfony/messenger)
    webhook:
        r2:

            # Enables /webhook/vadage_presigned_uploader_r2 for Cloudflare R2 event notifications
            secret:               null
        s3:

            # Enables /webhook/vadage_presigned_uploader_s3 for S3 event notifications
            secret:               null
```
