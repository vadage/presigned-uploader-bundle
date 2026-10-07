<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vadage\PresignedUploaderBundle\Form\PresignedUploadType;
use Vadage\PresignedUploaderBundle\Form\PresignedUploadTypeGuesser;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(PresignedUploadType::class)
        ->args([
            service('vadage_presigned_uploader.upload_manager'),
            service('vadage_presigned_uploader.owner_resolver'),
            service('vadage_presigned_uploader.mapping_registry'),
            service('vadage_presigned_uploader.storage_registry'),
            service('validator'),
            service('router'),
            service('security.csrf.token_manager')->nullOnInvalid(),
        ])
        ->tag('form.type');

    $services->set('vadage_presigned_uploader.form.type_guesser', PresignedUploadTypeGuesser::class)
        ->args([service('vadage_presigned_uploader.mapping_registry')])
        ->tag('form.type_guesser');
};
