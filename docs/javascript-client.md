# JavaScript client and HTTP endpoints

Use the client to upload files without the form type or Stimulus, e.g. from React, Vue or plain scripts.
Custom clients can call the [HTTP endpoints](#http-endpoints) directly.

## The client

The Stimulus controller is built on a framework-agnostic client. It presigns the upload, sends the file to the
storage with progress events, verifies it, and resolves with the upload id:

```js
import { upload, UploadError } from '@vadage/presigned-uploader-bundle/client.js';

const abortController = new AbortController();

try {
    const uploadId = await upload(file, {
        presignUrl: '/uploads/user_avatar',
        csrf: { header: 'X-CSRF-Token', token: csrfToken },   // when CSRF protection is enabled
        checksum: false,                       // true for mappings with checksum: true
        signal: abortController.signal,
        onProgress: (loaded, total) => console.log(Math.round((loaded / total) * 100) + '%'),
    });
    // submit uploadId with your form or API request
} catch (error) {
    if (error instanceof UploadError) {
        console.error(error.messages, error.fromServer);
    } else if (error.name === 'AbortError') {
        // aborted through the signal
    } else {
        throw error;
    }
}
```

| Option       | Type                      | Description                                                                                                                                 |
|--------------|---------------------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| `presignUrl` | `string`                  | URL of the presign endpoint of the mapping (required)                                                                                       |
| `verify`     | `boolean`                 | Verify the upload right after it finished, to report a rejected file before the form is submitted. Default `true`; claiming verifies anyway |
| `csrf`       | `{ header, token }`       | CSRF header and token                                                                                                                       |
| `checksum`   | `boolean`                 | Compute and send a base64 SHA-256 checksum (large files are hashed in chunks)                                                               |
| `signal`     | `AbortSignal`             | Aborts the upload; the promise then rejects with a `DOMException` named `AbortError`                                                        |
| `onProgress` | `(loaded, total) => void` | Upload progress of the `PUT` request                                                                                                        |

`upload()` rejects with an `UploadError` when the server refuses the upload (validation, denial, a rejected
file) or the transfer fails. `error.messages` holds the reasons; `error.fromServer` is `true` when they come
from the server and are therefore translated, and `false` for the client's own English fallback messages.

The verify call only fails the upload on a definitive answer (rejected, unknown or expired upload). Network or
server errors during verification are ignored; the claim verifies again.

The module also exports `sha256(blob)`, which returns the base64 SHA-256 of a `Blob`.

Generate the presign URL and the CSRF token on the server, e.g. in Twig; the verify URL comes with the presign
response:

```twig
<div data-presign-url="{{ path('vadage_presigned_uploader_presign', {mapping: 'user_avatar'}) }}"
     data-csrf-token="{{ csrf_token('vadage_presigned_uploader') }}"></div>
```

Then submit the upload id to your application: to an API that writes it with the
[serializer](api.md), or to your own code that resolves and claims it with
[the manual API](upload-lifecycle.md#without-doctrine-dtos).

### Importing the client

**Webpack Encore and other npm-based builds**: the package installed by Flex (`file:vendor/vadage/presigned-uploader-bundle/assets`)
exports the client as `@vadage/presigned-uploader-bundle/client` and `@vadage/presigned-uploader-bundle/client.js`,
with TypeScript types.

**AssetMapper**: the bundle maps its compiled files under the `@vadage/presigned-uploader-bundle` namespace,
so the client's logical path is `@vadage/presigned-uploader-bundle/client.js`. To import it by that name, add
an entry to your import map:

```php
// importmap.php
return [
    // ...
    '@vadage/presigned-uploader-bundle/client.js' => [
        'path' => '@vadage/presigned-uploader-bundle/client.js',
    ],
];
```

## HTTP endpoints

Both endpoints expect a JSON request body (`Content-Type: application/json`) and answer with JSON. When CSRF
protection is enabled, they require the token for the id `vadage_presigned_uploader` in the `X-CSRF-Token`
header and answer `403` without it; see [Security](security.md#csrf-protection) for clients that authenticate with tokens.
Sessions are used to identify anonymous users, so send cookies along (`credentials: 'same-origin'`).

### Presign: `POST /uploads/{mapping}`

```json
{ "filename": "avatar.png", "size": 48213, "mimeType": "image/png", "sha256": "base64..." }
```

`sha256` (base64 encoded) is required for mappings with `checksum: true` and ignored otherwise.

| Status            | Body                                                                                                            |
|-------------------|-----------------------------------------------------------------------------------------------------------------|
| `201`             | `{ "uploadId", "method", "url", "headers", "expiresAt", "verifyUrl" }`                                          |
| `422`             | `{ "violations": [{ "propertyPath", "message" }] }`                                                             |
| `403`, `429`, ... | `{ "message" }` when a [`PreSignEvent`](events-and-extension-points.md#presignevent) listener denied the upload |
| `404`             | Unknown mapping                                                                                                 |
| `400`, `415`      | Malformed or non-JSON body                                                                                      |

Then send the file with the returned `method` (`PUT`) to `url`, with exactly the returned `headers`
(`Content-Type`, and `If-None-Match` and `x-amz-checksum-sha256` when signed). The browser sets
`Content-Length` itself; it is part of the signature, so the body must be exactly the announced size. A `412`
answer means the object already exists, e.g. because a retried request's first response got lost.

### Verify: `POST /uploads/{uploadId}/verify`

Optional. Send an empty JSON object (`{}`) to the `verifyUrl` of the presign response.

| Status | Body (`{ "uploadId", "state", "violations" }`)                                                       |
|--------|------------------------------------------------------------------------------------------------------|
| `200`  | `state` is `verified` (or `claiming`)                                                                |
| `409`  | `pending`: the object is not in the storage yet                                                      |
| `422`  | `rejected`: the file failed verification and was deleted, `violations` says why                      |
| `410`  | `expired`                                                                                            |
| `404`  | Unknown upload, or the upload belongs to someone else (Symfony's error response, not the body above) |

With CSRF protection, a missing or invalid token gives `403` on both endpoints, also as Symfony's error
response.
