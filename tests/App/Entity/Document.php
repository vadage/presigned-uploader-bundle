<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GraphQl\Mutation;
use ApiPlatform\Metadata\GraphQl\Query;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Doctrine\StoredObjectType;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

#[ApiResource(
    operations: [new Get(), new Post(), new Patch()],
    normalizationContext: ['groups' => ['document:read']],
    denormalizationContext: ['groups' => ['document:write']],
    exceptionToStatus: [UploadNotClaimableException::class => 422],
    graphQlOperations: [new Query(), new Mutation(name: 'create'), new Mutation(name: 'update')],
)]
#[Uploadable]
#[ORM\Entity]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['document:read'])]
    public ?int $id = null;

    #[UploadableField(name: 'document_file', storage: 'private', uploadTtl: 600)]
    #[PresignedFile(maxSize: '1M', mimeTypes: ['application/pdf', 'text/plain'], extensions: ['pdf', 'txt'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    #[Groups(['document:read', 'document:write'])]
    public ?StoredObject $file = null;

    #[UploadableField(name: 'document_image', storage: 'public', prefix: 'images/', checksum: true)]
    #[PresignedFile(maxSize: '1M', mimeTypes: ['image/png'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    #[Groups(['document:read', 'document:write'])]
    public ?StoredObject $image = null;

    #[UploadableField(name: 'document_archive', storage: 'archive')]
    #[PresignedFile(maxSize: '1M')]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    #[Groups(['document:read', 'document:write'])]
    public ?StoredObject $archive = null;

    #[UploadableField(name: 'document_jar', storage: 'private')]
    #[PresignedFile(maxSize: '1M', mimeTypes: ['application/java-archive'])]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $jar = null;

    #[UploadableField(name: 'document_generated', storage: 'private', uploads: false)]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $generated = null;

    #[UploadableField(name: 'document_small', storage: 'private', security: 'subject.descriptor.size < 3')]
    #[PresignedFile(maxSize: '1M')]
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $small = null;

    /** A StoredObject column without #[UploadableField]: uploads cannot be claimed into it. */
    #[ORM\Column(type: StoredObjectType::NAME, nullable: true)]
    public ?StoredObject $copy = null;
}
