<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Vadage\PresignedUploaderBundle\Exception\MappingNotFoundException;
use Vadage\PresignedUploaderBundle\Exception\UploadDeniedException;
use Vadage\PresignedUploaderBundle\Exception\UploadValidationException;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;
use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;

/**
 * @internal
 */
final readonly class UploadController
{
    public const CSRF_TOKEN_ID = 'vadage_presigned_uploader';
    public const CSRF_HEADER = 'X-CSRF-Token';

    public function __construct(
        private UploadManager $manager,
        private UploadVerifier $verifier,
        private OwnerResolverInterface $ownerResolver,
        private UrlGeneratorInterface $urlGenerator,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    public function presign(Request $request, string $mapping): JsonResponse
    {
        $data = $this->decode($request);
        $sha256 = $data['sha256'] ?? null;
        if (!\is_string($data['filename'] ?? null) || !\is_int($data['size'] ?? null) || !\is_string($data['mimeType'] ?? null) || null !== $sha256 && !\is_string($sha256)) {
            throw new BadRequestHttpException('Expected "filename" (string), "size" (int), "mimeType" (string) and optionally "sha256" (string).');
        }

        try {
            $result = $this->manager->presign(
                $mapping,
                new UploadDescriptor($data['filename'], $data['size'], strtolower($data['mimeType']), $sha256),
                $this->ownerResolver->resolve(),
            );
        } catch (MappingNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (UploadValidationException $e) {
            return new JsonResponse(['violations' => self::violations($e->violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UploadDeniedException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->statusCode, $e->headers);
        }

        return new JsonResponse([
            'uploadId' => $result->token,
            'method' => $result->request->method,
            'url' => $result->request->url,
            'headers' => $result->request->headers,
            'expiresAt' => $result->request->expiresAt->format(\DATE_ATOM),
            'verifyUrl' => $this->urlGenerator->generate('vadage_presigned_uploader_verify', ['uploadId' => $result->token]),
        ], Response::HTTP_CREATED);
    }

    /**
     * Optional: verifies the upload right away, so the client learns about a rejection before the form is
     * submitted. Claiming verifies uploads that were not verified yet.
     */
    public function verify(Request $request, string $uploadId): JsonResponse
    {
        $this->decode($request);

        $upload = $this->manager->findByToken($uploadId);
        if (null === $upload || $upload->getOwnerId() !== $this->ownerResolver->resolve()) {
            throw new NotFoundHttpException('Upload not found.');
        }

        $result = $this->verifier->verify($upload);

        return new JsonResponse([
            'uploadId' => $uploadId,
            'state' => $result->state->value,
            'violations' => self::violations($result->violations),
        ], match ($result->state) {
            UploadState::Verified, UploadState::Claiming => Response::HTTP_OK,
            UploadState::Pending => Response::HTTP_CONFLICT,
            UploadState::Rejected => Response::HTTP_UNPROCESSABLE_ENTITY,
            UploadState::Expired => Response::HTTP_GONE,
        });
    }

    /**
     * A JSON body cannot be sent cross-site without a CORS preflight, which already prevents classic CSRF.
     * With symfony/security-csrf, a token is required on top.
     *
     * @return array<mixed>
     */
    private function decode(Request $request): array
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException('Expected a JSON request body.');
        }

        if (null !== $this->csrfTokenManager
            && !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get(self::CSRF_HEADER)))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        try {
            return '' === $request->getContent() ? [] : $request->toArray();
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('Invalid JSON body.', $e);
        }
    }

    /**
     * @return list<array{propertyPath: string, message: string}>
     */
    private static function violations(ConstraintViolationListInterface $violations): array
    {
        $result = [];
        foreach ($violations as $violation) {
            $result[] = ['propertyPath' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $result;
    }
}
