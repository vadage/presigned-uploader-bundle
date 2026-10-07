<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\Events;
use Symfony\Component\HttpKernel\KernelEvents;
use Vadage\PresignedUploaderBundle\Doctrine\UploadLifecycleListener;
use Vadage\PresignedUploaderBundle\Repository\DoctrinePendingUploadRepository;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('vadage_presigned_uploader.repository.doctrine', DoctrinePendingUploadRepository::class)
        ->args([service('doctrine')]);

    $services->set('vadage_presigned_uploader.doctrine.lifecycle_listener', UploadLifecycleListener::class)
        ->args([
            service('vadage_presigned_uploader.mapping_registry'),
            service('vadage_presigned_uploader.upload_manager'),
            service('vadage_presigned_uploader.object_deleter'),
            service('event_dispatcher'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('doctrine.event_listener', ['event' => Events::onFlush])
        ->tag('doctrine.event_listener', ['event' => Events::postFlush])
        ->tag('kernel.event_listener', ['event' => KernelEvents::TERMINATE, 'method' => 'reset'])
        ->tag('kernel.reset', ['method' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'presigned_uploader']);
};
