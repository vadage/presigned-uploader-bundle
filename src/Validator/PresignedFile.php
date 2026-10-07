<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Vadage\PresignedUploaderBundle\Util\ByteSize;

/**
 * Validates an upload before the presigned URL is created, and again against the stored object.
 *
 * Applies to UploadDescriptor and StoredObject values. Defaults to the "presign" group,
 * so it does not interfere with the regular validation of the owning entity.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
final class PresignedFile extends Constraint
{
    public const PRESIGN_GROUP = 'presign';

    public const TOO_LARGE_ERROR = '6cbac617-f5cc-4da2-a19d-cdeb7506eb54';
    public const EMPTY_ERROR = '8c364745-da09-4635-8f1f-78e54e929ef2';
    public const INVALID_MIME_TYPE_ERROR = '632a82cb-5177-4403-acaf-e7e5bbed6af8';
    public const INVALID_EXTENSION_ERROR = '9a8cd6ba-3865-44b1-8ddc-21cc8d1292c7';

    protected const ERROR_NAMES = [
        self::TOO_LARGE_ERROR => 'TOO_LARGE_ERROR',
        self::EMPTY_ERROR => 'EMPTY_ERROR',
        self::INVALID_MIME_TYPE_ERROR => 'INVALID_MIME_TYPE_ERROR',
        self::INVALID_EXTENSION_ERROR => 'INVALID_EXTENSION_ERROR',
    ];

    public readonly ?int $maxSize;

    /**
     * @param int|string|null $maxSize    bytes, or a string like "500k", "5M", "1Gi"
     * @param string[]        $mimeTypes  allowed MIME types, wildcards like "image/*" are supported
     * @param string[]        $extensions allowed file name extensions (without dot), checked against the client file name
     * @param string[]|null   $groups
     */
    public function __construct(
        int|string|null $maxSize = null,
        public readonly array $mimeTypes = [],
        public readonly array $extensions = [],
        public readonly string $maxSizeMessage = 'The file is too large ({{ size }} {{ suffix }}). Allowed maximum size is {{ limit }} {{ suffix }}.',
        public readonly string $emptyMessage = 'An empty file is not allowed.',
        public readonly string $mimeTypesMessage = 'The mime type of the file is invalid ({{ type }}). Allowed mime types are {{ types }}.',
        public readonly string $extensionsMessage = 'The extension of the file is invalid ({{ extension }}). Allowed extensions are {{ extensions }}.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups ?? [self::PRESIGN_GROUP], $payload);

        try {
            $this->maxSize = null === $maxSize ? null : ByteSize::parse($maxSize);
        } catch (\InvalidArgumentException $e) {
            throw new ConstraintDefinitionException($e->getMessage(), 0, $e);
        }
    }
}
