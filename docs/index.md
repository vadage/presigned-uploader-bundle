# VadagePresignedUploaderBundle documentation

## How it works

```
Browser                         Application                              Storage
  │ POST /uploads/{mapping}  ──►  validate (#[PresignedFile], "presign" group)
  │                               PreSignEvent (authorization, quotas)
  │ ◄── uploadId, url, headers    store PendingUpload, sign PUT
  │ PUT file ──────────────────────────────────────────────────────────►  checks signed headers
  │ POST /uploads/{id}/verify ──► (optional) HEAD + sniff + re-validate ◄─ storage event (optional)
  │ submit form (uploadId)  ───►  verify if needed → claim → (promote from staging) → persist
```

1. The browser announces the file (name, size, type, optionally a checksum) to the presign endpoint of a
   **mapping**, a property marked with `#[UploadableField]`.
2. The application validates the announcement against the property's constraints, lets listeners deny it,
   records a **pending upload** and returns a presigned `PUT` URL. Size, content type, checksum and
   `If-None-Match: *` are part of the signature, so the storage rejects anything else and a second write.
3. The browser uploads the file directly to the **storage**.
4. The form (or your own code) submits the upload id. The bundle **verifies** the stored object (size, type,
   checksum, MIME type sniffed from the first bytes, your constraints again) and **claims** it: the object
   moves to its final location if staging is used, and the reference is stored in your entity.
5. Uploads that are never claimed are deleted by the cleanup command.

## Contents

1. [Installation](installation.md): requirements, configuration, routes, database, frontend, CORS
2. [Mapping and validation](mapping-and-validation.md): `#[Uploadable]`, `#[UploadableField]`, `#[PresignedFile]`, reading stored objects
3. [Forms and the Stimulus controller](forms.md): `PresignedUploadType`, its theme, controller values and events
4. [JavaScript client and HTTP endpoints](javascript-client.md): uploading without Stimulus or forms
5. [APIs and API Platform](api.md): upload ids and stored objects in the serializer, API Platform REST, OpenAPI and GraphQL
6. [Storages, staging and Flysystem](storages.md): S3, R2, SeaweedFS, public URLs, staging, promoters, compatibility
7. [Upload lifecycle, transactions and cleanup](upload-lifecycle.md): states, verification, claims, the manual API for DTOs
8. [Storage events and webhooks](storage-events.md): verifying uploads as soon as the storage reports them
9. [Security](security.md): protecting the endpoints, CSRF, rate limits, public buckets
10. [Events and extension points](events-and-extension-points.md): events, namers, promoters, owners, repositories
11. [Translations](translations.md): included languages and translation domains
12. [Configuration reference](configuration-reference.md): every configuration option

## Vocabulary

| Term          | Meaning                                                                                              |
|---------------|------------------------------------------------------------------------------------------------------|
| Storage       | A named bucket (and/or Flysystem filesystem) configured under `vadage_presigned_uploader.storages`   |
| Mapping       | A property marked with `#[UploadableField]`, identified by its public `name`                         |
| Upload        | A pending upload: from presigning until it is claimed, rejected or expired                           |
| Stored object | A `StoredObject`, the value of a mapped property: storage, key, size, MIME type, original name       |
| Claim         | Attaching a verified upload to an entity or DTO; the upload record is deleted when the claim commits |
| Staging       | Uploading to another storage (or prefix) first and promoting the object when it is claimed           |
