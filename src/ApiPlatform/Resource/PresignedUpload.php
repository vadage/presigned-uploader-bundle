<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GraphQl\Mutation;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Vadage\PresignedUploaderBundle\ApiPlatform\CreatePresignedUploadProcessor;
use Vadage\PresignedUploaderBundle\ApiPlatform\VerifyPresignedUploadResolver;

#[ApiResource(
    operations: [],
    graphQlOperations: [
        new Mutation(
            description: 'Presigns an upload for a mapping. Send the file with "method" to "url" with exactly "headers", then pass "uploadId" where the mapped property is written.',
            input: CreatePresignedUploadInput::class,
            name: 'create',
            processor: CreatePresignedUploadProcessor::class,
        ),
        new Mutation(
            resolver: VerifyPresignedUploadResolver::class,
            description: 'Verifies a finished upload, so a rejected file is reported before the upload id is used. Optional: claiming verifies anyway.',
            input: VerifyPresignedUploadInput::class,
            read: false,
            write: false,
            name: 'verify',
        ),
    ],
)]
final class PresignedUpload
{
    /**
     * @param array<string, string>          $headers
     * @param list<PresignedUploadViolation> $violations
     */
    public function __construct(
        #[ApiProperty(description: 'Pass it where the mapped property is written', identifier: false)]
        public string $uploadId,
        #[ApiProperty(description: 'pending, verified, claiming, rejected or expired')]
        public string $state,
        #[ApiProperty(description: 'HTTP method of the upload request (PUT)')]
        public ?string $method = null,
        #[ApiProperty(description: 'Presigned URL to send the file to')]
        public ?string $url = null,
        #[ApiProperty(description: 'Headers to send with the upload request, exactly as given')]
        public ?array $headers = null,
        #[ApiProperty(description: 'When the presigned URL expires (ISO 8601)')]
        public ?string $expiresAt = null,
        // The @param type needs a PHPDoc extractor
        #[ApiProperty(
            description: 'Why the file was rejected',
            nativeType: new CollectionType(new GenericType(new BuiltinType(TypeIdentifier::ARRAY), new BuiltinType(TypeIdentifier::INT), new ObjectType(PresignedUploadViolation::class)), true),
        )]
        public array $violations = [],
    ) {
    }
}
