<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Vadage\PresignedUploaderBundle\ApiPlatform\StoredObjectGraphQlType;
use Vadage\PresignedUploaderBundle\ApiPlatform\StoredObjectPropertyMetadataFactory;
use Vadage\PresignedUploaderBundle\ApiPlatform\StoredObjectTypeConverter;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Between the factories reading the metadata and the one completing the JSON schema (priority 10).
    $services->set('vadage_presigned_uploader.api_platform.property_metadata_factory', StoredObjectPropertyMetadataFactory::class)
        ->decorate('api_platform.metadata.property.metadata_factory', null, 15, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner')]);

    // The interface of API Platform 4 has no convertPhpType() yet.
    if (interface_exists(TypeConverterInterface::class) && (new \ReflectionClass(TypeConverterInterface::class))->hasMethod('convertPhpType')) {
        $services->set('vadage_presigned_uploader.api_platform.graphql_type_converter', StoredObjectTypeConverter::class)
            ->decorate('api_platform.graphql.type_converter', null, 0, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
            ->args([service('.inner')]);
        $services->set('vadage_presigned_uploader.api_platform.graphql_type', StoredObjectGraphQlType::class)
            ->tag('api_platform.graphql.type');
    }
};
