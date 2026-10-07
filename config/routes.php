<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Vadage\PresignedUploaderBundle\Controller\UploadController;

/*
 * Import in the application, e.g. config/routes/vadage_presigned_uploader.yaml:
 *
 *     vadage_presigned_uploader:
 *         resource: '@VadagePresignedUploaderBundle/config/routes.php'
 *         prefix: /uploads
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('vadage_presigned_uploader_presign', '/{mapping}')
        ->controller([UploadController::class, 'presign'])
        ->methods(['POST'])
        ->requirements(['mapping' => '[A-Za-z0-9_.-]+'])
        ->format('json');

    $routes->add('vadage_presigned_uploader_verify', '/{uploadId}/verify')
        ->controller([UploadController::class, 'verify'])
        ->methods(['POST'])
        ->requirements(['uploadId' => '[A-Za-z0-9_.-]+'])
        ->format('json');
};
