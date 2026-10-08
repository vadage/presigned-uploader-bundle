<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\GraphQl\Resolver\MutationResolverInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\PresignedUpload;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\PresignedUploadViolation;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\VerifyPresignedUploadInput;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;
use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;

/**
 * @internal
 */
final readonly class VerifyPresignedUploadResolver implements MutationResolverInterface
{
    public function __construct(
        private UploadManager $manager,
        private UploadVerifier $verifier,
        private OwnerResolverInterface $ownerResolver,
    ) {
    }

    public function __invoke(?object $item, array $context): PresignedUpload
    {
        if (!$item instanceof VerifyPresignedUploadInput) {
            throw new \InvalidArgumentException(\sprintf('Expected a "%s".', VerifyPresignedUploadInput::class));
        }

        $upload = $this->manager->findOwnedByToken($item->uploadId, $this->ownerResolver->resolve());
        if (null === $upload) {
            throw new NotFoundHttpException('Upload not found.');
        }

        $result = $this->verifier->verify($upload);

        return new PresignedUpload($item->uploadId, $result->state->value, violations: PresignedUploadViolation::fromList($result->violations));
    }
}
