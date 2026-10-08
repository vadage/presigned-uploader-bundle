<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Vadage\PresignedUploaderBundle\ApiPlatform\CreatePresignedUploadProcessor;
use Vadage\PresignedUploaderBundle\ApiPlatform\PresignedUploadResourceNameCollectionFactory;
use Vadage\PresignedUploaderBundle\ApiPlatform\VerifyPresignedUploadResolver;
use Vadage\PresignedUploaderBundle\Upload\OwnerResolverInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('vadage_presigned_uploader.api_platform.resource_name_collection_factory', PresignedUploadResourceNameCollectionFactory::class)
        ->decorate('api_platform.metadata.resource.name_collection_factory', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner')]);

    $services->set(CreatePresignedUploadProcessor::class)
        ->args([service('vadage_presigned_uploader.upload_manager'), service(OwnerResolverInterface::class)])
        ->tag('api_platform.state_processor');

    $services->set(VerifyPresignedUploadResolver::class)
        ->args([service('vadage_presigned_uploader.upload_manager'), service('vadage_presigned_uploader.verifier'), service(OwnerResolverInterface::class)])
        ->tag('api_platform.graphql.resolver');
};
