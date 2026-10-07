<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vadage\PresignedUploaderBundle\Serializer\StoredObjectNormalizer;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('vadage_presigned_uploader.serializer.normalizer', StoredObjectNormalizer::class)
        ->args([
            service('vadage_presigned_uploader.upload_manager'),
            service('vadage_presigned_uploader.owner_resolver'),
            service('vadage_presigned_uploader.storage_registry'),
        ])
        ->tag('serializer.normalizer');
};
