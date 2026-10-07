<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vadage\PresignedUploaderBundle\Command\CleanupCommand;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('vadage_presigned_uploader.command.cleanup', CleanupCommand::class)
        ->args([service('vadage_presigned_uploader.sweeper')])
        ->tag('console.command');
};
