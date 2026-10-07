<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Dto;

use Vadage\PresignedUploaderBundle\Attribute\Uploadable;

#[Uploadable]
final class ContractAttachment extends Attachment
{
}
