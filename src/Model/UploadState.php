<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Model;

enum UploadState: string
{
    /** Presigned URL handed out, upload not verified yet. */
    case Pending = 'pending';
    /** Object exists in the storage and passed verification, waiting to be claimed. */
    case Verified = 'verified';
    /**
     * Being attached to an entity or DTO. The record is deleted in the same transaction that stores the
     * reference; a claim that never commits falls back to Verified once its lease ran out.
     */
    case Claiming = 'claiming';
    /** Failed verification, the object has been deleted. */
    case Rejected = 'rejected';
    /** Never claimed in time, the object is being deleted. */
    case Expired = 'expired';
}
