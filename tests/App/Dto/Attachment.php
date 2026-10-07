<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Dto;

use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

/**
 * Not discovered automatically: listed under "mapped_classes" with its subclasses by the tests that need it.
 */
#[Uploadable]
abstract class Attachment
{
    #[UploadableField(name: 'attachment', storage: 'private')]
    #[PresignedFile(maxSize: '1M')]
    public ?StoredObject $file = null;
}
