<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vadage\PresignedUploaderBundle\Controller\UploadController;
use Vadage\PresignedUploaderBundle\Exception\UploadNotClaimableException;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Model\StoredObject;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

/**
 * The file goes directly to the storage (Stimulus controller), the form only submits the upload token.
 *
 * @extends AbstractType<StoredObject|null>
 */
final class PresignedUploadType extends AbstractType
{
    public const STIMULUS_CONTROLLER = 'vadage--presigned-uploader-bundle--upload';
    public const TRANSLATION_DOMAIN = 'VadagePresignedUploaderBundle';

    public function __construct(
        private readonly UploadManager $manager,
        private readonly OwnerResolverInterface $ownerResolver,
        private readonly MappingRegistry $mappings,
        private readonly StorageRegistry $storages,
        private readonly ValidatorInterface $validator,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $mapping = $options['mapping'];
        \assert(\is_string($mapping));

        $builder->add('token', HiddenType::class, ['error_bubbling' => true, 'invalid_message' => $options['invalid_message'], 'mapped' => false]);
        $builder->get('token')->addModelTransformer(new CallbackTransformer(
            static fn (): string => '',
            function (mixed $token) use ($mapping): ?StoredObject {
                if (!\is_string($token) || '' === $token) {
                    return null;
                }

                try {
                    return $this->manager->resolveClaimable($token, $mapping, $this->ownerResolver->resolve());
                } catch (UploadNotClaimableException $e) {
                    // A rejected file shows why; anything else shows the "invalid_message" option.
                    $reasons = [];
                    foreach ($e->violations ?? [] as $violation) {
                        $reasons[] = (string) $violation->getMessage();
                    }

                    throw new TransformationFailedException($e->getMessage(), 0, $e, [] !== $reasons ? implode(' ', $reasons) : null);
                }
            },
        ));

        if (true === $options['allow_delete']) {
            $builder->add('delete', CheckboxType::class, ['label' => $options['delete_label'], 'translation_domain' => self::TRANSLATION_DOMAIN, 'required' => false, 'mapped' => false]);
        }

        // A new upload replaces the current object, "delete" clears it, otherwise it stays untouched.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            if (null !== $uploaded = $form->get('token')->getData()) {
                $event->setData($uploaded);
            } elseif ($form->has('delete') && true === $form->get('delete')->getData()) {
                $event->setData(null);
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        \assert(\is_string($options['mapping']));
        $mapping = $this->mappings->get($options['mapping']);
        $current = $form->getData();
        [$maxSize, $accept] = $this->clientHints($mapping->class, $mapping->property);

        // The widget is compound, so the label would point nowhere; point it at the file input.
        $id = $view->vars['id'];
        $labelAttr = $view->vars['label_attr'];
        if (\is_string($id) && \is_array($labelAttr)) {
            $view->vars['label_attr'] = $labelAttr + ['for' => $id.'_file'];
        }
        $view->vars += [
            'translation_domain_ui' => self::TRANSLATION_DOMAIN,
            'stored_object' => $current,
            'download_url' => true === $options['download_uri'] && $current instanceof StoredObject
                ? $this->storages->objects($current->getStorage())->url($current->getKey())
                : null,
            'stimulus_controller' => self::STIMULUS_CONTROLLER,
            'presign_url' => $this->urlGenerator->generate('vadage_presigned_uploader_presign', ['mapping' => $mapping->name]),
            'csrf_header' => UploadController::CSRF_HEADER,
            'csrf_token' => $this->csrfTokenManager?->getToken(UploadController::CSRF_TOKEN_ID)->getValue(),
            'checksum' => $mapping->checksum,
            'max_size' => $maxSize,
            'accept' => $accept,
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('mapping')
            ->setAllowedTypes('mapping', 'string')
            ->setAllowedValues('mapping', $this->mappings->has(...))
            ->setDefaults([
                'allow_delete' => true,
                'delete_label' => 'Delete',
                'download_uri' => true,
                'data_class' => StoredObject::class,
                'empty_data' => null,
                'error_bubbling' => false,
                'invalid_message' => 'The uploaded file is no longer available, please upload it again.',
            ])
            ->setAllowedTypes('allow_delete', 'bool')
            ->setAllowedTypes('download_uri', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'vadage_presigned_upload';
    }

    /**
     * For the user experience only, the server stays authoritative.
     *
     * @return array{?int, string} max size, value of the "accept" attribute
     */
    private function clientHints(string $class, string $property): array
    {
        $maxSize = null;
        $accept = [];

        $metadata = $this->validator->getMetadataFor($class);
        \assert($metadata instanceof ClassMetadataInterface);
        foreach ($metadata->getPropertyMetadata($property) as $propertyMetadata) {
            foreach ($propertyMetadata->findConstraints(PresignedFile::PRESIGN_GROUP) as $constraint) {
                if (!$constraint instanceof PresignedFile) {
                    continue;
                }
                if (null !== $constraint->maxSize) {
                    $maxSize = min($maxSize ?? $constraint->maxSize, $constraint->maxSize);
                }
                array_push($accept, ...$constraint->mimeTypes, ...array_map(static fn (string $e): string => '.'.$e, $constraint->extensions));
            }
        }

        return [$maxSize, implode(',', array_unique($accept))];
    }
}
