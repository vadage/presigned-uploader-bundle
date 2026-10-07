<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonbType;
use Vadage\PresignedUploaderBundle\Model\StoredObject;

/**
 * Stores a StoredObject as JSON (JSONB on PostgreSQL). Usage: #[ORM\Column(type: StoredObjectType::NAME, nullable: true)].
 */
final class StoredObjectType extends JsonbType
{
    public const NAME = 'presigned_stored_object';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!$value instanceof StoredObject) {
            throw InvalidType::new($value, self::NAME, ['null', StoredObject::class]);
        }

        return parent::convertToDatabaseValue($value->toArray(), $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?StoredObject
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        return \is_array($data) ? StoredObject::fromArray($data) : throw InvalidFormat::new(\is_scalar($data) ? (string) $data : get_debug_type($data), self::NAME, 'JSON object');
    }
}
