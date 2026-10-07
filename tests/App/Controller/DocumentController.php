<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Vadage\PresignedUploaderBundle\Tests\App\Entity\Document;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;

/**
 * A typical application controller using the form type.
 */
final readonly class DocumentController
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private Environment $twig,
        private UploadManager $manager,
        private ?ManagerRegistry $doctrine = null,
    ) {
    }

    public function __invoke(Request $request, ?int $id = null): Response
    {
        $document = null === $id ? new Document() : $this->doctrine?->getManager()->find(Document::class, $id);
        if (null === $document) {
            throw new NotFoundHttpException();
        }

        $form = $this->formFactory->createNamedBuilder('document', FormType::class, $document, ['data_class' => Document::class])
            ->add('file')
            ->add('image')
            ->add('archive')
            ->getForm();

        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return new Response($this->twig->createTemplate('{{ form(form) }}')->render(['form' => $form->createView()]));
        }
        if (!$form->isValid()) {
            return new JsonResponse(['errors' => (string) $form->getErrors(true)], 422);
        }

        if (null !== $this->doctrine) {
            $em = $this->doctrine->getManager();
            $em->persist($document);
            $em->flush();
        } else {
            // Without Doctrine, claim before storing the reference and commit afterwards.
            foreach ([$document->file, $document->image, $document->archive] as $object) {
                if (null !== $object && null !== $claim = $this->manager->claim($object)) {
                    $this->manager->commitClaim($claim);
                }
            }
        }

        return new JsonResponse([
            'id' => $document->id,
            'file' => $document->file?->toArray(),
            'image' => $document->image?->toArray(),
            'archive' => $document->archive?->toArray(),
        ]);
    }
}
