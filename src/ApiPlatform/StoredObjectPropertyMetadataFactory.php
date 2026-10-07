<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

/**
 * Documents StoredObject properties as the StoredObjectNormalizer reads and writes them: an upload id in
 * requests, an object with a URL in responses. A schema declared with #[ApiProperty(schema: ...)] is kept.
 *
 * @internal
 */
final readonly class StoredObjectPropertyMetadataFactory implements PropertyMetadataFactoryInterface
{
    public function __construct(
        private PropertyMetadataFactoryInterface $decorated,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function create(string $resourceClass, string $property, array $options = []): ApiProperty
    {
        $metadata = $this->decorated->create($resourceClass, $property, $options);
        $type = $metadata->getNativeType();
        if (null !== $metadata->getSchema() || null === $type || !self::isStoredObject($type)) {
            return $metadata;
        }

        $nullable = $type->isNullable();
        if (Schema::TYPE_INPUT === ($options['schema_type'] ?? null)) {
            return $metadata->withSchema([
                'type' => $nullable ? ['string', 'null'] : 'string',
                'description' => 'The id of an upload, from the presign endpoint of the mapping'.($nullable ? '; null removes the file' : ''),
            ]);
        }

        return $metadata->withSchema([
            'type' => $nullable ? ['object', 'null'] : 'object',
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Public URL, or a presigned URL valid for a few minutes'],
                'size' => ['type' => 'integer', 'description' => 'Size in bytes'],
                'mimeType' => ['type' => 'string'],
                'originalName' => ['type' => 'string', 'description' => 'The file name on the uploader\'s device'],
                'sha256' => ['type' => ['string', 'null'], 'description' => 'Base64 SHA-256 checksum, if the mapping requires one'],
                'uploadedAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['url', 'size', 'mimeType', 'originalName', 'sha256', 'uploadedAt'],
        ]);
    }

    public static function isStoredObject(Type $type): bool
    {
        return $type->isSatisfiedBy(static fn (Type $type): bool => $type instanceof ObjectType && is_a($type->getClassName(), StoredObject::class, true));
    }
}
