<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Attribute;

/**
 * Maps a ?StoredObject property to a storage. Upload constraints go next to it (#[PresignedFile]).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class UploadableField
{
    /**
     * @param string      $name            public, stable identifier used in upload URLs
     * @param string      $storage         name of a storage configured under vadage_presigned_uploader.storages
     * @param string|null $namer           service id of a NamerInterface, defaults to the configured default namer
     * @param string      $prefix          key prefix passed to the namer, e.g. "avatars/"
     * @param bool        $checksum        require a SHA-256 checksum from the client and let the storage verify it
     * @param bool|null   $staging         null inherits the storage's staging config, false disables it
     * @param bool|null   $sniffContent    null inherits the default, false disables the magic-byte check
     * @param int|null    $uploadTtl       lifetime of the presigned PUT in seconds, null inherits the default
     * @param bool        $deleteOnRemove  delete the stored object when the owning entity is removed
     * @param bool        $deleteOnReplace delete the previous stored object when it is replaced or cleared
     * @param bool        $uploads         false for properties only the application writes: no presigning, no maximum size
     * @param string|null $security        expression the authorization checker has to grant before presigning, e.g.
     *                                     "is_granted('ROLE_USER')", with the PreSignEvent as subject
     */
    public function __construct(
        public string $name,
        public string $storage,
        public ?string $namer = null,
        public string $prefix = '',
        public bool $checksum = false,
        public ?bool $staging = null,
        public ?bool $sniffContent = null,
        public ?int $uploadTtl = null,
        public bool $deleteOnRemove = true,
        public bool $deleteOnReplace = true,
        public bool $uploads = true,
        public ?string $security = null,
    ) {
    }
}
