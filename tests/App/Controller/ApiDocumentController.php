<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * A JSON API writing and reading documents with the serializer, like API Platform or #[MapRequestPayload].
 */
final readonly class ApiDocumentController
{
    private const MAPPINGS = ['file' => 'document_file', 'image' => 'document_image', 'archive' => 'document_archive'];

    public function __construct(
        private SerializerInterface $serializer,
        private NormalizerInterface $normalizer,
        private UploadManager $manager,
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function __invoke(Request $request, ?int $id = null): JsonResponse
    {
        $document = null === $id ? new Document() : $this->doctrine?->getManager()->find(Document::class, $id);
        if (null === $document) {
            throw new NotFoundHttpException();
        }

        try {
            $this->serializer->deserialize($request->getContent(), Document::class, 'json', [
                AbstractNormalizer::OBJECT_TO_POPULATE => $document,
                AbstractNormalizer::ATTRIBUTES => array_keys(self::MAPPINGS),
            ]);
        } catch (NotNormalizableValueException $e) {
            return new JsonResponse(['path' => $e->getPath(), 'message' => $e->getMessage()], 400);
        }

        try {
            if (null !== $this->doctrine) {
                $em = $this->doctrine->getManager();
                $em->persist($document);
                $em->flush();
            } else {
                foreach (self::MAPPINGS as $property => $mapping) {
                    $object = $document->{$property};
                    if (null !== $object && null !== $claim = $this->manager->claim($object, $mapping)) {
                        $this->manager->commitClaim($claim);
                    }
                }
            }
        } catch (UploadNotClaimableException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse($this->normalizer->normalize($document, 'json', [AbstractNormalizer::ATTRIBUTES => ['id', ...array_keys(self::MAPPINGS)]]));
    }
}
