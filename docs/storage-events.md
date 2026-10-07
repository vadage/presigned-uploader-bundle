# Storage events and webhooks

Storage events are optional: claiming an upload verifies it anyway. They let you react before the form is
submitted, e.g. to start processing a file in an `UploadVerifiedEvent` listener.

## The `ObjectCreated` message

With symfony/messenger installed, dispatch `ObjectCreated` from your own queue consumer (SQS, a relay, ...):

```php
use Vadage\PresignedUploaderBundle\Messenger\ObjectCreated;

$bus->dispatch(new ObjectCreated($bucket, $key));
```

The handler looks up the upload presigned for that bucket and key and verifies it. Objects the bundle did not
presign (including promoted copies) are ignored. Route the message to an async transport if you like:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            Vadage\PresignedUploaderBundle\Messenger\ObjectCreated: async
```

## Webhooks

Most storage providers publish object events to queues or message buses rather than calling your application.
A relay (e.g. a queue consumer) forwards each event to a webhook endpoint and signs it. Each provider has its own
endpoint and secret, through Symfony's Webhook component. This requires `symfony/webhook`,
`symfony/remote-event` and `symfony/messenger`.

Enable a provider by setting its secret:

```yaml
vadage_presigned_uploader:
    webhook:
        r2: { secret: '%env(R2_WEBHOOK_SECRET)%' }
        s3: { secret: '%env(S3_WEBHOOK_SECRET)%' }
```

| Config              | Endpoint                                     | Payload                                                                                                                                                                                                         |
|---------------------|----------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `webhook.r2.secret` | `POST /webhook/vadage_presigned_uploader_r2` | One [R2 event notification](https://developers.cloudflare.com/r2/buckets/event-notifications/) message                                                                                                          |
| `webhook.s3.secret` | `POST /webhook/vadage_presigned_uploader_s3` | An [S3 event notification](https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html) (`{"Records": [...]}`) from AWS (via SQS, SNS or EventBridge) or an S3-compatible storage |

Object creation events (R2: `PutObject`, `CopyObject`, `CompleteMultipartUpload`; S3: `ObjectCreated:*`) verify
the matching upload. Other events are accepted and ignored, so the relay does not retry them.

Import the webhook routes if your application does not already:

```yaml
# config/routes/webhook.yaml
webhook:
    resource: '@FrameworkBundle/Resources/config/routing/webhook.php'
    prefix: /webhook
```

The Webhook component hands each event to Messenger. Route `ConsumeRemoteEventMessage` to an async transport
to answer the relay right away:

```yaml
framework:
    messenger:
        routing:
            Symfony\Component\RemoteEvent\Messenger\ConsumeRemoteEventMessage: async
```

### Signing requests

The relay sends the provider's JSON payload unchanged as body, with two headers:

- `X-Webhook-Timestamp`: the current Unix time; requests more than 5 minutes off are rejected
- `X-Webhook-Signature`: `hex(HMAC-SHA256(secret, "<timestamp>.<raw body>"))`

A relay written in PHP can use the bundle's helper:

```php
use Vadage\PresignedUploaderBundle\Webhook\AbstractSignedRequestParser;

$timestamp = time();
$signature = AbstractSignedRequestParser::sign($body, $timestamp, $secret);
```

### Cloudflare R2

Send R2 event notifications for `object-create` to a Cloudflare Queue, and consume it with a Worker that
forwards each message to `/webhook/vadage_presigned_uploader_r2`:

```js
export default {
    async queue(batch, env) {
        const encoder = new TextEncoder();
        const key = await crypto.subtle.importKey('raw', encoder.encode(env.WEBHOOK_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);

        for (const message of batch.messages) {
            const body = JSON.stringify(message.body);
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(`${timestamp}.${body}`));
            const hex = [...new Uint8Array(signature)].map((b) => b.toString(16).padStart(2, '0')).join('');

            const response = await fetch(env.WEBHOOK_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Webhook-Timestamp': timestamp, 'X-Webhook-Signature': hex },
                body,
            });
            response.ok ? message.ack() : message.retry();
        }
    },
};
```

Set `WEBHOOK_URL` (e.g. `https://app.example.com/webhook/vadage_presigned_uploader_r2`) as a variable and
`WEBHOOK_SECRET` as a secret of the Worker.

### AWS S3 and S3-compatible storages

Deliver S3 event notifications to a relay of your choice (e.g. an SQS consumer or a Lambda function) that posts
the notification (`{"Records": [...]}`) to `/webhook/vadage_presigned_uploader_s3` with the headers above.
Alternatively, consume the queue in your application and dispatch [`ObjectCreated`](#the-objectcreated-message)
for each record. Object keys in S3 notifications are URL-encoded (with `+` for spaces): decode them with
`urldecode()` first.
