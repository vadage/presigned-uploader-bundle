# Security

Say only logged-in users may change their avatar, but anyone may attach a CV to a job application. The
presign endpoint has to let the anonymous applicant through and turn away anonymous avatar uploads. The
applicant's upload id must not be usable by anyone else, and a script must not be able to fill your bucket
with thousands of CVs.

## Authorization

Presigning is public unless you restrict it. Declare who may upload with the `security` option, an expression
like the one of `#[IsGranted]`. It is checked before the file is validated and the
[`PreSignEvent`](events-and-extension-points.md#presignevent) is dispatched, for the HTTP endpoints and the
[GraphQL mutations](api.md#presigning-with-graphql) alike:

```php
#[UploadableField(name: 'user_avatar', storage: 'media', security: "is_granted('ROLE_USER')")]
```

`is_granted()` also asks your voters. The subject is the `PreSignEvent`, so expressions and voters can look at
the mapping and the announced file, e.g. `subject.descriptor.size`. A denied upload answers `403`. The option
requires the SecurityBundle and symfony/expression-language.

### Protecting the endpoints

The mapping name is part of the presign URL, so `access_control` can also decide per mapping:

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/uploads/user_avatar$, roles: ROLE_USER }
        - { path: ^/uploads/job_application$, roles: PUBLIC_ACCESS }
```

Anchor the patterns with `$`: `^/uploads/user_avatar` would also match a mapping named `user_avatar_large`.
The verify endpoint (`/uploads/{uploadId}/verify`) only answers to the owner of the upload.

For quotas or rules that need more than the security component, deny the upload in a
[`PreSignEvent`](events-and-extension-points.md#presignevent) listener. It receives the mapping, the announced
file and the owner id.

## Owners

Every upload belongs to the user or session that presigned it. Only the owner can verify it, and only the owner
can claim it through the form type (the manual API takes the owner as argument). The default
`OwnerResolver` returns:

- `user:<user identifier>` for authenticated users,
- `session:<SHA-256 of the session id>` otherwise, so the session id itself is never stored or passed on. This
  requires sessions; the bundle stores a flag in the session so that it is persisted.

**Caveat**: if the session id changes between the upload and the form submission, e.g. because the user logs in
(Symfony migrates the session on login), an anonymous upload can no longer be claimed and the user has to upload
the file again. To change how owners are identified, [replace the owner resolver](events-and-extension-points.md#owner-resolver).

Upload ids are signed with `kernel.secret`, so they cannot be guessed or enumerated. Changing the secret
invalidates the ids of pending uploads.

## CSRF protection

When CSRF protection is enabled (symfony/security-csrf installed and `framework.csrf_protection` on), both
endpoints require a token for the id `vadage_presigned_uploader` in the `X-CSRF-Token` header. The form widget
passes it to the Stimulus controller; for your own client, see [JavaScript client](javascript-client.md).

Without a CSRF token manager, the endpoints still only accept JSON bodies, which browsers cannot send cross-site
without a CORS preflight.

Clients that authenticate with a token in a header instead of a cookie (mobile apps, API clients) have no CSRF
token to send, and are not exposed to CSRF in the first place. Turn the check off for them:

```yaml
vadage_presigned_uploader:
    csrf_protection: false
```

This turns it off for the form widget too. Browsers then still need a CORS preflight to send the JSON body
cross-site, so the endpoints stay protected as long as your CORS configuration does not allow other origins
with credentials.

## GraphQL

The [GraphQL mutations](api.md#presigning-with-graphql) do not use CSRF tokens: they are part of your GraphQL
API, and whoever may call it may presign uploads, subject to the `security` option of the mapping. That suits clients
that authenticate with tokens. For browsers that authenticate with cookies, your GraphQL endpoint is as exposed
to CSRF as any of its mutations: make sure it only accepts requests that browsers cannot send cross-site without
a CORS preflight (e.g. `application/json` bodies).

`access_control` cannot tell the mappings apart on the GraphQL endpoint; use the [`security`](#authorization)
option.

## Quotas and rate limits

Listen to `PreSignEvent` and call `deny()` with a reason, an HTTP status code and headers. For example, with the
RateLimiter component and a limiter named `uploads`:

```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        uploads:
            policy: sliding_window
            limit: 50
            interval: '1 hour'
```

```php
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Vadage\PresignedUploaderBundle\Event\PreSignEvent;

#[AsEventListener]
final readonly class UploadRateLimiter
{
    public function __construct(private RateLimiterFactoryInterface $uploadsLimiter, private RequestStack $requestStack)
    {
    }

    public function __invoke(PreSignEvent $event): void
    {
        // Anonymous users can start new sessions at will, limit them by IP address.
        $key = str_starts_with($event->ownerId, 'user:') ? $event->ownerId : 'ip:'.$this->requestStack->getMainRequest()?->getClientIp();
        $limit = $this->uploadsLimiter->create($key)->consume();
        if (!$limit->isAccepted()) {
            $event->deny('Too many uploads, please try again later.', 429, ['Retry-After' => (string) ($limit->getRetryAfter()->getTimestamp() - time())]);
        }
    }
}
```

The reason is returned to the client as it is; translate it in the listener if needed.

## Public buckets

Public buckets serve files as they were uploaded. Allowing `image/svg+xml` (or `image/*`, which includes it)
lets users upload SVG files with scripts that run on the bucket's domain. Serve such files from a separate
domain, or don't allow them. HTML and similar types carry the same risk.

Without staging, an object in a public bucket is reachable as soon as it is uploaded, before it is verified and
claimed, and the uploader knows its URL. Use [staging](storages.md#staging) for public, user generated content
from untrusted users, and prefer private storages (presigned `GET` URLs) otherwise.

## Other notes

- Object keys come from the [namer](events-and-extension-points.md#namer). The default `UuidNamer` uses a random
  UUID and an extension derived from the validated MIME type; never derive keys from the client's file name
  alone.
- `StoredObject::getOriginalName()` and the announced MIME type are client input, validated by your
  constraints but not trustworthy beyond that.
- Never build a `StoredObject` from client input: whoever chooses its storage and key can read and delete any
  object in your storages through your application. Clients send upload ids, which the form type and the
  [serializer](api.md) resolve for their owner.
- Keep webhook secrets long and random, and different per provider.
