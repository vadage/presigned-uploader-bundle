<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Vadage\PresignedUploaderBundle\Webhook\R2RequestParser;
use Vadage\PresignedUploaderBundle\Webhook\S3EventRequestParser;
use Vadage\PresignedUploaderBundle\Webhook\StorageEventConsumer;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('vadage_presigned_uploader.webhook.parser.r2', R2RequestParser::class)
        ->args([service('clock')]);

    $services->set('vadage_presigned_uploader.webhook.parser.s3', S3EventRequestParser::class)
        ->args([service('clock')]);

    // Tagged as remote event consumer per enabled provider by the bundle.
    $services->set('vadage_presigned_uploader.webhook.consumer', StorageEventConsumer::class)
        ->args([service('vadage_presigned_uploader.verifier')]);
};
