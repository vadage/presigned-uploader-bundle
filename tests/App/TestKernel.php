<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use AsyncAws\S3\S3Client;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\ErrorHandler\ErrorHandler;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Vadage\PresignedUploaderBundle\Tests\App\Controller\ApiDocumentController;
use Vadage\PresignedUploaderBundle\Tests\App\Controller\DocumentController;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array{doctrine?: bool, entity_managers?: bool, csrf?: bool, api_platform?: bool, config?: array<string, mixed>, storages?: array<string, mixed>} $options
     */
    public function __construct(string $environment = 'test', bool $debug = true, private readonly array $options = [])
    {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        if ($this->usesDoctrine()) {
            yield new DoctrineBundle();
        }
        if ($this->usesApiPlatform()) {
            yield new ApiPlatformBundle();
        }
        yield new VadagePresignedUploaderBundle();
    }

    /**
     * Booting a debug kernel registers Symfony's error handler, which Symfony 7 leaves in place;
     * PHPUnit 11+ reports that as risky. Call after the kernel is shut down.
     */
    public static function restoreExceptionHandler(): void
    {
        $current = set_exception_handler(null);
        restore_exception_handler();
        if (\is_array($current) && $current[0] instanceof ErrorHandler) {
            restore_exception_handler();
        }
    }

    public static function databaseUrl(): string
    {
        $url = $_SERVER['DATABASE_URL'] ?? null;

        return \is_string($url) && '' !== $url ? $url : 'sqlite:///:memory:';
    }

    public static function seaweedfsEndpoint(): string
    {
        $endpoint = $_SERVER['SEAWEEDFS_ENDPOINT'] ?? null;

        return \is_string($endpoint) ? $endpoint : 'http://127.0.0.1:8333';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test-secret',
            'http_method_override' => false,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => $this->options['csrf'] ?? false,
            'form' => ['csrf_protection' => false],
            'serializer' => true,
            'property_info' => true,
            'validation' => true,
            'property_access' => true,
            'messenger' => true,
            'webhook' => true,
            'remote-event' => true,
            'router' => ['utf8' => true],
            'translator' => ['fallbacks' => ['en']],
            'enabled_locales' => ['en', 'de', 'fr'],
            'set_locale_from_accept_language' => true,
        ]);

        $container->extension('vadage_presigned_uploader', array_replace_recursive([
            'storages' => $this->options['storages'] ?? [
                'private' => ['client' => 'test.s3_client', 'bucket' => 'test-private'],
                'public' => [
                    'client' => 'test.s3_client',
                    'bucket' => 'test-public',
                    'public_url' => self::seaweedfsEndpoint().'/test-public',
                    'staging' => ['storage' => 'quarantine'],
                ],
                'quarantine' => ['client' => 'test.s3_client', 'bucket' => 'test-quarantine'],
                // No S3 client: promoted into by streaming the staged object through the application.
                'archive' => ['filesystem' => 'test.archive_filesystem', 'staging' => ['storage' => 'quarantine', 'prefix' => 'archive/']],
            ],
            'pending_upload' => ['repository' => $this->usesDoctrine() ? 'doctrine' : InMemoryPendingUploadRepository::class],
            'webhook' => ['r2' => ['secret' => 'webhook-secret'], 's3' => ['secret' => 'webhook-secret']],
        ], $this->options['config'] ?? []));

        if ($this->usesDoctrine()) {
            $orm = ['mappings' => ['TestApp' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => __DIR__.'/Entity',
                'prefix' => 'Vadage\PresignedUploaderBundle\Tests\App\Entity',
            ]]];
            $container->extension('doctrine', [
                'dbal' => ['url' => self::databaseUrl()],
                'orm' => ($this->options['entity_managers'] ?? false)
                    ? ['default_entity_manager' => 'main', 'entity_managers' => ['main' => $orm, 'other' => []]]
                    : $orm,
            ]);
        }

        if ($this->usesApiPlatform()) {
            $container->extension('api_platform', [
                'mapping' => ['paths' => [__DIR__.'/Entity']],
                'formats' => ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
                'patch_formats' => ['json' => ['application/merge-patch+json']],
                'docs_formats' => ['jsonopenapi' => ['application/vnd.openapi+json']],
                'enable_swagger_ui' => false,
                'enable_re_doc' => false,
                'graphql' => ['enabled' => true, 'graphiql' => ['enabled' => false]],
            ]);
        }

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('logger', NullLogger::class);

        // Like an application's "App\: resource: ../src/": #[Uploadable] classes are discovered from here.
        $services->load('Vadage\PresignedUploaderBundle\Tests\App\Entity\\', __DIR__.'/Entity');
        $services->set(InMemoryPendingUploadRepository::class);
        $services->set(DocumentController::class)->public()->tag('controller.service_arguments');
        $services->set(ApiDocumentController::class)->public()->tag('controller.service_arguments');

        $services->set('test.archive_filesystem', Filesystem::class)
            ->public()
            ->args([inline_service(InMemoryFilesystemAdapter::class)]);

        $services->set('test.s3_client', S3Client::class)
            ->public()
            ->args([[
                'endpoint' => self::seaweedfsEndpoint(),
                'region' => 'us-east-1',
                'pathStyleEndpoint' => true,
                'accessKeyId' => 'test-access-key',
                'accessKeySecret' => 'test-secret-key',
            ]]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@VadagePresignedUploaderBundle/config/routes.php')->prefix('/uploads');
        $routes->import('@FrameworkBundle/Resources/config/routing/webhook.php')->prefix('/webhook');
        $routes->add('documents_new', '/documents/new')->controller(DocumentController::class);
        $routes->add('documents_edit', '/documents/{id<\d+>}')->controller(DocumentController::class);
        if ($this->usesApiPlatform()) {
            $routes->import('.', 'api_platform')->prefix('/apip');
        }
        $routes->add('api_documents_new', '/api/documents')->controller(ApiDocumentController::class)->methods(['POST']);
        $routes->add('api_documents_edit', '/api/documents/{id<\d+>}')->controller(ApiDocumentController::class)->methods(['PATCH']);
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return $this->baseDir().'/cache';
    }

    public function getLogDir(): string
    {
        return $this->baseDir().'/log';
    }

    private function usesDoctrine(): bool
    {
        return $this->options['doctrine'] ?? false;
    }

    private function usesApiPlatform(): bool
    {
        return $this->options['api_platform'] ?? false;
    }

    private function baseDir(): string
    {
        return sys_get_temp_dir().'/vadage_presigned_uploader/'.$this->environment.'/'.hash('xxh128', serialize($this->options));
    }
}
