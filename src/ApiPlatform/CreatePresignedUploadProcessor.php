<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\CreatePresignedUploadInput;
use Vadage\PresignedUploaderBundle\ApiPlatform\Resource\PresignedUpload;
use Vadage\PresignedUploaderBundle\Exception\MappingNotFoundException;
use Vadage\PresignedUploaderBundle\Exception\UploadDeniedException;
use Vadage\PresignedUploaderBundle\Exception\UploadValidationException;
use Vadage\PresignedUploaderBundle\Model\UploadDescriptor;
use Vadage\PresignedUploaderBundle\Model\UploadState;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * @implements ProcessorInterface<CreatePresignedUploadInput, PresignedUpload>
 *
 * @internal
 */
final readonly class CreatePresignedUploadProcessor implements ProcessorInterface
{
    public function __construct(
        private UploadManager $manager,
        private OwnerResolverInterface $ownerResolver,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PresignedUpload
    {
        if (!$data instanceof CreatePresignedUploadInput) {
            throw new \InvalidArgumentException(\sprintf('Expected a "%s".', CreatePresignedUploadInput::class));
        }
        if ($data->size !== floor($data->size) || $data->size < 0 || $data->size > \PHP_INT_MAX) {
            throw new BadRequestHttpException('"size" has to be a whole number of bytes.');
        }

        try {
            $result = $this->manager->presign(
                $data->mapping,
                new UploadDescriptor($data->filename, (int) $data->size, strtolower($data->mimeType), $data->sha256),
                $this->ownerResolver->resolve(),
            );
        } catch (MappingNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (UploadValidationException $e) {
            throw new ValidationException($e->violations, previous: $e);
        } catch (UploadDeniedException $e) {
            throw new HttpException($e->statusCode, $e->getMessage(), $e, $e->headers);
        }

        return new PresignedUpload(
            $result->token,
            UploadState::Pending->value,
            $result->request->method,
            $result->request->url,
            $result->request->headers,
            $result->request->expiresAt->format(\DATE_ATOM),
        );
    }
}
