<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Dto;

use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

/**
 * Not discovered automatically: listed under "mapped_classes" by the tests that need it.
 */
#[Uploadable]
final class UnlimitedUpload
{
    #[UploadableField(name: 'unlimited', storage: 'private')]
    public ?StoredObject $file = null;
}
