# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-07

### Added

- Presigned direct-to-storage uploads for S3-compatible storages (AWS S3, Cloudflare R2, SeaweedFS, ...), with
  size, content type, optional SHA-256 checksum and single write (`If-None-Match: *`) enforced by the signature
- `#[Uploadable]` and `#[UploadableField]` to map `StoredObject` properties of entities and DTOs to storages,
  checked when the container is compiled
- `#[PresignedFile]` constraint, validated before signing and again against the stored object with the
  sniffed MIME type; every mapping requires a maximum size
- Verification when an upload is claimed, or earlier through the optional verify endpoint, the `ObjectCreated`
  Messenger message, or signed R2 and S3 event notification webhooks
- Doctrine integration: `presigned_stored_object` column type, and claims and object deletions that follow the
  flush and outer transactions (with tombstones)
- Manual API (`UploadManager::resolveClaimable()`, `claim()`, `commitClaim()`, `abandonClaim()`, `delete()`) for DTOs and other
  persistence layers
- Named storages with public URLs or presigned reads, optional staging with promotion on claim
  (`PromoterInterface`), and Flysystem storages as promotion targets
- `PresignedUploadType` with a type guesser, a form theme and a Stimulus controller; a framework-agnostic
  JavaScript client
- `PreSignEvent` (with `deny()` for authorization, quotas and rate limits), `UploadVerifiedEvent`,
  `UploadRejectedEvent` and `UploadClaimedEvent`
- Extension points: `NamerInterface`, `PromoterInterface`, `OwnerResolverInterface`,
  `PendingUploadRepositoryInterface`
- CSRF protection of the upload endpoints when symfony/security-csrf is enabled, with a `csrf_protection`
  option to turn it off for clients that authenticate with tokens
- Serializer support (API Platform, `#[MapRequestPayload]`): `StoredObject` properties are written with upload
  ids and read with a URL
- API Platform integration: OpenAPI schemas for `StoredObject` properties, and GraphQL types (API Platform 5)
- `vadage:presigned-uploader:cleanup` command for unclaimed uploads, stale claims and obsolete objects
- English, German and French translations

[0.1.0]: https://github.com/vadage/presigned-uploader-bundle/releases/tag/v0.1.0
