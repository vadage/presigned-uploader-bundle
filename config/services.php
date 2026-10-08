<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use AsyncAws\Core\Credentials\ChainProvider;
use Vadage\PresignedUploaderBundle\Controller\UploadController;
use Vadage\PresignedUploaderBundle\Mapping\MappingRegistry;
use Vadage\PresignedUploaderBundle\Naming\UuidNamer;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Staging\CopyPromoter;
use Vadage\PresignedUploaderBundle\Storage\StorageRegistry;
use Vadage\PresignedUploaderBundle\Upload\ObjectDeleter;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolver;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;
use Vadage\PresignedUploaderBundle\Upload\UploadManager;
use Vadage\PresignedUploaderBundle\Upload\UploadSweeper;
use Vadage\PresignedUploaderBundle\Upload\UploadVerifier;
use Vadage\PresignedUploaderBundle\Validator\PresignedFileValidator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('vadage_presigned_uploader.mapping_registry', MappingRegistry::class)
        ->args([abstract_arg('mappings, set by MappingPass')])
        ->alias(MappingRegistry::class, 'vadage_presigned_uploader.mapping_registry');

    $services->set('vadage_presigned_uploader.storage_registry', StorageRegistry::class)
        ->args([abstract_arg('uploads locator'), abstract_arg('object stores locator'), abstract_arg('storage name => bucket')])
        ->alias(StorageRegistry::class, 'vadage_presigned_uploader.storage_registry');

    $services->set('vadage_presigned_uploader.credential_provider', ChainProvider::class)
        ->factory([ChainProvider::class, 'createDefaultChain'])
        ->args([service('http_client')->nullOnInvalid(), service('logger')->nullOnInvalid()]);

    $services->set(UuidNamer::class);
    $services->set(CopyPromoter::class);

    $services->set('vadage_presigned_uploader.owner_resolver', OwnerResolver::class)
        ->args([service('request_stack'), service('security.token_storage')->nullOnInvalid()])
        ->alias(OwnerResolverInterface::class, 'vadage_presigned_uploader.owner_resolver');

    $services->set('vadage_presigned_uploader.upload_manager', UploadManager::class)
        ->args([
            service('vadage_presigned_uploader.mapping_registry'),
            service('vadage_presigned_uploader.storage_registry'),
            service(PendingUploadRepositoryInterface::class),
            service('validator'),
            service('event_dispatcher'),
            service('clock'),
            abstract_arg('namers used by the mappings, set by MappingPass'),
            abstract_arg('promoters used by the mappings, set by MappingPass'),
            service('vadage_presigned_uploader.verifier'),
            service('vadage_presigned_uploader.object_deleter'),
            param('kernel.secret'),
            param('vadage_presigned_uploader.claim_ttl'),
            service('logger')->ignoreOnInvalid(),
            service('translator')->nullOnInvalid(),
            service('security.authorization_checker')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'presigned_uploader'])
        ->alias(UploadManager::class, 'vadage_presigned_uploader.upload_manager');

    $services->set('vadage_presigned_uploader.object_deleter', ObjectDeleter::class)
        ->args([
            service('vadage_presigned_uploader.mapping_registry'),
            service('vadage_presigned_uploader.storage_registry'),
            service(PendingUploadRepositoryInterface::class),
            service('clock'),
        ]);

    $services->set('vadage_presigned_uploader.sweeper', UploadSweeper::class)
        ->args([
            service(PendingUploadRepositoryInterface::class),
            service('vadage_presigned_uploader.storage_registry'),
            service('vadage_presigned_uploader.object_deleter'),
            service('clock'),
            param('vadage_presigned_uploader.claim_lease'),
        ])
        ->alias(UploadSweeper::class, 'vadage_presigned_uploader.sweeper');

    $services->set('vadage_presigned_uploader.verifier', UploadVerifier::class)
        ->args([
            service('vadage_presigned_uploader.mapping_registry'),
            service('vadage_presigned_uploader.storage_registry'),
            service(PendingUploadRepositoryInterface::class),
            service('validator'),
            service('event_dispatcher'),
            service('clock'),
            service('logger')->ignoreOnInvalid(),
            service('translator')->nullOnInvalid(),
            service('security.authorization_checker')->nullOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'presigned_uploader'])
        ->alias(UploadVerifier::class, 'vadage_presigned_uploader.verifier');

    $services->set(PresignedFileValidator::class)->tag('validator.constraint_validator');

    $services->set(UploadController::class)
        ->public()
        ->args([
            service('vadage_presigned_uploader.upload_manager'),
            service('vadage_presigned_uploader.verifier'),
            service('vadage_presigned_uploader.owner_resolver'),
            service('router'),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->tag('controller.service_arguments');
};
