<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\GraphQl\Type\Definition\TypeInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL output type of StoredObject properties, as the StoredObjectNormalizer reads them.
 *
 * @internal
 */
final class StoredObjectGraphQlType extends ObjectType implements TypeInterface
{
    public const NAME = 'StoredObject';

    public function __construct()
    {
        parent::__construct([
            'name' => self::NAME,
            'description' => 'A file in a storage',
            'fields' => [
                'url' => ['type' => Type::nonNull(Type::string()), 'description' => 'Public URL, or a presigned URL valid for a few minutes'],
                // GraphQL's Int has 32 bits, uploads can have up to 5 GiB.
                'size' => ['type' => Type::nonNull(Type::float()), 'description' => 'Size in bytes'],
                'mimeType' => Type::nonNull(Type::string()),
                'originalName' => ['type' => Type::nonNull(Type::string()), 'description' => 'The file name on the uploader\'s device'],
                'sha256' => ['type' => Type::string(), 'description' => 'Base64 SHA-256 checksum, if the mapping requires one'],
                'uploadedAt' => ['type' => Type::nonNull(Type::string()), 'description' => 'ISO 8601 date'],
            ],
        ]);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
