<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vadage\PresignedUploaderBundle\Messenger\ObjectCreatedHandler;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('vadage_presigned_uploader.messenger.object_created_handler', ObjectCreatedHandler::class)
        ->args([service('vadage_presigned_uploader.verifier')])
        ->tag('messenger.message_handler');
};
