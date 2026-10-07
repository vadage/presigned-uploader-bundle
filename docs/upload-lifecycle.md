# Upload lifecycle, transactions and cleanup

Take the avatar from the [README](../README.md) example. A user picks a file, the Stimulus controller sends
it to the storage, and the form submits the upload id. At that point the file is in the bucket, but nothing
references it yet: the user may close the tab, another field may fail validation, or the flush may roll back.
Later, when the user replaces their avatar, the old file has to be deleted too, but only once the new reference
is committed.

The bundle tracks every upload from the moment it hands out the URL until the file is attached, and deletes
whatever is left behind. The sections below follow the avatar through those steps.

## States

Each presigned upload is recorded as a `PendingUpload` (table `vadage_presigned_upload` with Doctrine) until it
is claimed, or cleaned up.

```
pending ──► verified ──► claiming ──► (record deleted when the claim commits)
   │            │            │
   │            │            └──► verified again when the claim never commits (after claim_lease)
   │            └──► expired (not claimed within claim_ttl, object deleted)
   ├──► rejected (failed verification, object deleted)
   └──► expired
```

| State      | Meaning                                                                                                              |
|------------|----------------------------------------------------------------------------------------------------------------------|
| `pending`  | The presigned URL was handed out; the upload is not verified yet                                                     |
| `verified` | The object exists and passed verification; it waits to be claimed                                                    |
| `claiming` | The upload is being attached to an entity or DTO. The record is deleted in the transaction that stores the reference |
| `rejected` | The object failed verification and was deleted                                                                       |
| `expired`  | The upload was not claimed in time; the cleanup command deletes the object and the record                            |

The timing is configured under `defaults`:

| Option        | Default | Description                                                                                                   |
|---------------|---------|---------------------------------------------------------------------------------------------------------------|
| `upload_ttl`  | `300`   | Seconds a presigned `PUT` stays valid (at most 7 days); per mapping with `#[UploadableField(uploadTtl: ...)]` |
| `claim_ttl`   | `86400` | Seconds an upload can be claimed; must be longer than every upload TTL                                        |
| `claim_lease` | `900`   | Seconds after which the cleanup command reverts a claim that never committed                                  |

## Verification

Verification compares the stored object with what was signed and validated: size, content type and checksum,
then the `presign` constraints with the MIME type [sniffed](mapping-and-validation.md#content-sniffing) from
the stored bytes. A file that fails is deleted, the upload becomes `rejected`, and an `UploadRejectedEvent` is
dispatched.

Verification happens at the latest when the upload is claimed, so nothing else is required. It can happen
earlier through:

- the optional verify call (`POST /uploads/{uploadId}/verify`), which the Stimulus controller makes right
  after the upload so the user learns about a rejected file before submitting the form;
- a [storage event](storage-events.md), when the storage reports the new object.

Whichever comes first wins; later calls see the current state. `UploadVerifiedEvent` is dispatched once per
upload.

## Claiming with Doctrine

When you flush an entity whose mapped property received a new `StoredObject` (e.g. from the form), the bundle's
flush listener:

1. moves the upload from `verified` to `claiming` and promotes it from staging, before the flush transaction;
2. deletes the upload record in the flush transaction, together with the entity change;
3. records the objects that became obsolete (the replaced value with `deleteOnReplace`, the values of removed
   entities with `deleteOnRemove`) and the staging object as **tombstones** in the same transaction;
4. after the flush committed, dispatches `UploadClaimedEvent` and deletes the objects behind the tombstones.

An upload can only be claimed once. The upload id is bound to its mapping and its owner (the user, or the
session for anonymous users); see [Security](security.md#owners).

### Transactions

Claims and deletions follow the transaction that stores the references:

- **The flush fails**: the tombstones are rolled back, so no referenced object is deleted. The claims of the
  failed flush are abandoned at the end of the request (or between messages in a worker), so the user can submit
  the form again right away. If that does not happen, the cleanup command reverts them after `claim_lease`.
- **The flush runs inside an outer transaction** (e.g. Messenger's `doctrine_transaction` middleware, or
  `EntityManager::wrapInTransaction()`): nothing is committed after the flush, so objects are not deleted right
  away. Once the outer transaction commits, the cleanup command deletes them from the stored tombstones. If it
  rolls back, the tombstones disappear and the objects are kept. `UploadClaimedEvent` is dispatched after the
  flush, so in this case the outer transaction may still roll back.
- **A delete fails** after the commit: the tombstone stays and the cleanup command retries.

While a presigned `PUT` is still valid, it can recreate a deleted object (e.g. a staging object right after the
claim). Tombstones are therefore kept until the URL expired, and the cleanup command deletes such objects again.

An object that moves to another property or entity in the same flush (e.g. `$new->file = $old->file` while
`$old` is removed, or two entities swapping files) is not deleted. Don't store one object in two places at the
same time, though: deleting one of them deletes the object.

Uploads can only be claimed into properties with `#[UploadableField]`; flushing an upload in another
`presigned_stored_object` column, or in an embeddable, fails instead of losing the upload later.

Keep the bundle's entities in the same entity manager as your mapped entities (`pending_upload.entity_manager`).
Otherwise these guarantees do not hold, and the listener logs a warning when it cannot keep them.

Promotion from staging happens inside `flush()`, before the database transaction. For large files that are
streamed between storages, this makes the flush slow.

## Without Doctrine (DTOs)

For DTOs, other persistence layers, or the form type without Doctrine, use `UploadManager` directly:

```php
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

public function __construct(
    private UploadManager $uploads,
    private OwnerResolverInterface $ownerResolver,
) {
}

public function attachAvatar(string $uploadId): void
{
    // Verifies the upload if needed; throws UploadNotClaimableException if it cannot be used.
    $object = $this->uploads->resolveClaimable($uploadId, 'user_avatar', $this->ownerResolver->resolve());

    $claim = $this->uploads->claim($object);   // before storing the reference: promotes from staging

    try {
        // ... store the reference, e.g. $object->toArray() ...
    } catch (\Throwable $e) {
        if (null !== $claim) {
            $this->uploads->abandonClaim($claim);   // the upload can be claimed again right away
        }
        throw $e;
    }

    if (null !== $claim) {
        $this->uploads->commitClaim($claim);   // afterwards: removes the record and the staging object
    }
}
```

`resolveClaimable()` returns a `StoredObject` at its final location; the form type and the
[serializer](api.md) call it for you, so with them you start at `claim()`. The serializer cannot tell which
property it writes to, so it resolves uploads for any mapping: pass the mapping of the property to `claim()`
(`claim($object, 'user_avatar')`), which then fails for uploads made for another mapping. `claim()` returns `null` for objects that do not come from an upload (e.g. a
value loaded from your database) and throws `UploadNotClaimableException` when the upload was claimed already.
`commitClaim()` dispatches `UploadClaimedEvent`.

Always commit a claim once the reference is stored. A claim that is neither committed nor abandoned is reverted
after `claim_lease`, and the upload is deleted when it expires, even if you stored a reference to it.

To delete an object you no longer reference, call `delete()` after the change is stored:

```php
$this->uploads->delete($oldObject);
```

It deletes the object now and records a tombstone if a presigned `PUT` could still recreate it.

## Cleanup

The cleanup command:

1. reverts claims that never committed (after `claim_lease`),
2. deletes uploads that were not claimed within `claim_ttl`, with their objects (the records of rejected
   uploads are removed at that point too),
3. deletes the objects behind tombstones (replaced, removed and staging objects), and the tombstones once no
   presigned `PUT` can recreate their object.

```bash
php bin/console vadage:presigned-uploader:cleanup
php bin/console vadage:presigned-uploader:cleanup --limit=1000   # records per step, default 500
```

Failed storage operations are reported as warnings and retried on the next run; the command then exits with a
non-zero status, so monitoring notices. Run the command every few minutes, e.g. with the Scheduler component:

```php
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('uploads')]
final class UploadCleanupSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('5 minutes', new RunCommandMessage('vadage:presigned-uploader:cleanup')),
        );
    }
}
```

and consume it with `php bin/console messenger:consume scheduler_uploads`. A cron job works as well:

```
*/5 * * * * php /path/to/app/bin/console vadage:presigned-uploader:cleanup --no-interaction
```
