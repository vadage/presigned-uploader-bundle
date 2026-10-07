<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Model;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An object to delete once the transaction that wrote this record has committed.
 *
 * The object is deleted as soon as possible, but the record is kept until "notBefore": while a
 * presigned PUT for the key is still valid, the object can be recreated and is deleted again.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vadage_presigned_upload_tombstone')]
#[ORM\Index(name: 'vadage_presigned_upload_tombstone_due', columns: ['not_before'])]
class ObjectTombstone
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: 'uuid')]
        private Uuid $id,
        #[ORM\Column(name: 'storage', length: 100)]
        private string $storage,
        #[ORM\Column(name: 'object_key', length: 1024)]
        private string $key,
        #[ORM\Column(name: 'not_before', type: 'datetime_immutable')]
        private \DateTimeImmutable $notBefore,
    ) {
    }

    public static function create(string $storage, string $key, \DateTimeImmutable $notBefore): self
    {
        return new self(Uuid::v7(), $storage, $key, $notBefore);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStorage(): string
    {
        return $this->storage;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getNotBefore(): \DateTimeImmutable
    {
        return $this->notBefore;
    }
}
