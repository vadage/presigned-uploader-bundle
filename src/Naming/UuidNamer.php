<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Naming;

use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Uid\Uuid;

/**
 * "<prefix><random uuid>.<extension>", the extension is derived from the validated MIME type.
 */
final readonly class UuidNamer implements NamerInterface
{
    public function __construct(private MimeTypes $mimeTypes = new MimeTypes())
    {
    }

    public function name(UploadContext $context): string
    {
        $extension = $this->mimeTypes->getExtensions($context->descriptor->mimeType)[0] ?? null;

        return $context->mapping->prefix.Uuid::v4()->toRfc4122().(null !== $extension ? '.'.$extension : '');
    }
}
