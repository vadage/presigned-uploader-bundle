<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use AsyncAws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Webhook\Controller\WebhookController;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Controller\UploadController;
use Vadage\PresignedUploaderBundle\DependencyInjection\Compiler\MappingPass;
use Vadage\PresignedUploaderBundle\Doctrine\StoredObjectType;
use Vadage\PresignedUploaderBundle\Form\PresignedUploadType;
use Vadage\PresignedUploaderBundle\Naming\NamerInterface;
use Vadage\PresignedUploaderBundle\Naming\UuidNamer;
use Vadage\PresignedUploaderBundle\Repository\PendingUploadRepositoryInterface;
use Vadage\PresignedUploaderBundle\Staging\CopyPromoter;
use Vadage\PresignedUploaderBundle\Staging\PromoterInterface;
use Vadage\PresignedUploaderBundle\Storage\FlysystemObjectStore;
use Vadage\PresignedUploaderBundle\Storage\S3Storage;
use Vadage\PresignedUploaderBundle\Util\ByteSize;
use Vadage\PresignedUploaderBundle\Util\TypedArray;

final class VadagePresignedUploaderBundle extends AbstractBundle
{
    public const UPLOADABLE_TAG = 'vadage_presigned_uploader.uploadable';
    /** Longest validity of a SigV4 presigned URL. */
    public const MAX_UPLOAD_TTL = 604800;
    /** Largest object a single S3 PUT can create. */
    public const MAX_UPLOAD_SIZE = 5 * 1024 ** 3;
    /**
     * Webhook routing keys (/webhook/{type}) per provider, also used as remote event consumer names.
     * Kept here, not on the webhook classes: reading them must not autoload optional dependencies.
     */
    public const WEBHOOK_TYPES = [
        'r2' => 'vadage_presigned_uploader_r2',
        's3' => 'vadage_presigned_uploader_s3',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('storages')
                    ->info('Named storages, referenced by #[UploadableField(storage: ...)]')
                    ->useAttributeAsKey('name')
                    // Storage names are referenced verbatim by #[UploadableField(storage: ...)].
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->validate()
                            ->ifTrue(static fn (array $s): bool => (null === ($s['client'] ?? null)) !== (null === ($s['bucket'] ?? null)))
                            ->thenInvalid('"client" and "bucket" have to be configured together.')
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $s): bool => null === ($s['client'] ?? null) && null === ($s['filesystem'] ?? null))
                            ->thenInvalid('A storage needs a "client" and "bucket", a "filesystem", or both.')
                        ->end()
                        ->children()
                            ->scalarNode('client')->defaultNull()->info('Service id of an '.S3Client::class.'; required for storages that receive presigned uploads')->end()
                            ->scalarNode('bucket')->defaultNull()->end()
                            ->scalarNode('filesystem')->defaultNull()->info('Service id of a Flysystem FilesystemOperator for reading, writing and deleting objects instead of the S3 client; a storage with only a filesystem can be a staging target, but receives no uploads')->end()
                            ->scalarNode('public_url')->defaultNull()->info('Base URL for public reads (e.g. a CDN or R2 custom domain); null reads via presigned GET (or the filesystem\'s temporary URLs)')->end()
                            ->booleanNode('conditional_put')->defaultTrue()->info('Sign "If-None-Match: *" so a presigned URL can only write once; disable for storages not supporting it')->end()
                            ->scalarNode('credential_provider')->defaultNull()->info('Service id of an AsyncAws CredentialProvider; defaults to the async-aws default chain (client configuration, env vars, ...)')->end()
                            ->arrayNode('staging')
                                ->info('Upload to another storage first and promote on claim')
                                ->canBeEnabled()
                                ->children()
                                    ->scalarNode('storage')->defaultNull()->info('Storage the browser uploads to')->end()
                                    ->scalarNode('prefix')->defaultValue('incoming/')->end()
                                    ->scalarNode('promoter')->defaultValue(CopyPromoter::class)->info('Service id of a '.PromoterInterface::class)->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('upload_ttl')->min(1)->max(self::MAX_UPLOAD_TTL)->defaultValue(300)->info('Seconds a presigned PUT stays valid (SigV4 allows at most 7 days)')->end()
                        ->integerNode('claim_ttl')->min(60)->defaultValue(86400)->info('Seconds an upload can be claimed before it is cleaned up')->end()
                        ->integerNode('claim_lease')->min(60)->defaultValue(900)->info('Seconds after which the cleanup command reverts a claim whose transaction never committed')->end()
                        ->scalarNode('namer')->defaultValue(UuidNamer::class)->info('Service id of a '.NamerInterface::class)->end()
                        ->booleanNode('sniff_content')->defaultTrue()->info('Validate the MIME type detected from the stored bytes instead of the declared one')->end()
                        ->scalarNode('max_size')
                            ->defaultNull()
                            ->info('Largest upload for mappings without #[PresignedFile(maxSize: ...)], e.g. "50M"; required if there are such mappings')
                            ->validate()
                                ->ifTrue(static function (mixed $size): bool {
                                    try {
                                        return !\is_int($size) && !\is_string($size) || ByteSize::parse($size) > self::MAX_UPLOAD_SIZE;
                                    } catch (\InvalidArgumentException) {
                                        return true;
                                    }
                                })
                                ->thenInvalid('%s is not a size of at most 5 GiB (S3 limits single uploads to that), e.g. 50M or 1Gi.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('csrf_protection')
                    ->defaultTrue()
                    ->info('Require a CSRF token on the upload endpoints when symfony/security-csrf is installed; disable it for clients that authenticate with tokens instead of cookies')
                ->end()
                ->arrayNode('graphql')
                    ->info('The createPresignedUpload and verifyPresignedUpload mutations (requires API Platform 5 with GraphQL)')
                    ->canBeEnabled()
                ->end()
                ->arrayNode('mapped_classes')
                    ->info('Classes with #[Uploadable] that are not discovered automatically (e.g. excluded from service registration)')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('pending_upload')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('repository')->defaultValue('doctrine')->info('"doctrine" or the service id of a '.PendingUploadRepositoryInterface::class)->end()
                        ->scalarNode('entity_manager')->defaultNull()->info('Entity manager the bundle\'s entities are mapped in; defaults to the default entity manager. Use the one your uploadable entities live in, so claims share their transaction')->end()
                    ->end()
                ->end()
                ->arrayNode('webhook')
                    ->info('Storage event webhooks, enabled per provider by setting a secret (requires symfony/webhook, symfony/remote-event and symfony/messenger)')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('r2')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('secret')->defaultNull()->info('Enables /webhook/'.self::WEBHOOK_TYPES['r2'].' for Cloudflare R2 event notifications')->end()
                            ->end()
                        ->end()
                        ->arrayNode('s3')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('secret')->defaultNull()->info('Enables /webhook/'.self::WEBHOOK_TYPES['s3'].' for S3 event notifications')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $c): bool => TypedArray::int(TypedArray::array($c, 'defaults'), 'claim_ttl') <= TypedArray::int(TypedArray::array($c, 'defaults'), 'upload_ttl'))
                ->thenInvalid('"defaults.claim_ttl" must be longer than "defaults.upload_ttl".')
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $prepend = ['dbal' => ['types' => [StoredObjectType::NAME => StoredObjectType::class]]];
            $orm = ['mappings' => ['VadagePresignedUploaderBundle' => [
                'type' => 'attribute',
                'is_bundle' => false,
                'dir' => __DIR__.'/Model',
                'prefix' => 'Vadage\PresignedUploaderBundle\Model',
            ]]];
            // With a custom repository, the bundle's tables (and the flush listener's tombstones) would be unused.
            if ('doctrine' === $this->pendingUploadOption($builder, 'repository', 'doctrine')) {
                $entityManager = $this->pendingUploadOption($builder, 'entity_manager', null) ?? $this->defaultEntityManager($builder);
                $prepend['orm'] = null === $entityManager ? $orm : ['entity_managers' => [$entityManager => $orm]];
            }
            $builder->prependExtensionConfig('doctrine', $prepend);
        }

        if ($builder->hasExtension('twig') && interface_exists(FormTypeInterface::class)) {
            $builder->prependExtensionConfig('twig', ['form_themes' => ['@VadagePresignedUploader/form/theme.html.twig']]);
        }

        if (interface_exists(AssetMapperInterface::class)) {
            $builder->prependExtensionConfig('framework', ['asset_mapper' => ['paths' => [
                \dirname(__DIR__).'/assets/dist' => '@vadage/presigned-uploader-bundle',
            ]]]);
        }

        if (!self::webhookAvailable()) {
            return;
        }
        $secrets = [];
        foreach ($builder->getExtensionConfig($this->extensionAlias) as $config) {
            foreach (self::WEBHOOK_TYPES as $provider => $type) {
                $webhook = $config['webhook'] ?? null;
                if (\is_array($webhook) && \is_array($webhook[$provider] ?? null) && isset($webhook[$provider]['secret'])) {
                    $secrets[$provider] = $webhook[$provider]['secret'];
                }
            }
        }
        foreach ($secrets as $provider => $secret) {
            $builder->prependExtensionConfig('framework', ['webhook' => ['routing' => [self::WEBHOOK_TYPES[$provider] => [
                'service' => 'vadage_presigned_uploader.webhook.parser.'.$provider,
                'secret' => $secret,
            ]]]]);
        }
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');

        $bundles = $builder->getParameter('kernel.bundles');
        $doctrine = \is_array($bundles) && isset($bundles['DoctrineBundle']);
        if ($doctrine) {
            $container->import('../config/doctrine.php');
        }
        if (interface_exists(FormTypeInterface::class)) {
            $container->import('../config/form.php');
        }
        if (interface_exists(NormalizerInterface::class)) {
            $container->import('../config/serializer.php');
        }
        if (interface_exists(PropertyMetadataFactoryInterface::class)) {
            $container->import('../config/api_platform.php');
        }
        if (self::graphQlAvailable() && TypedArray::bool(TypedArray::array($config, 'graphql'), 'enabled')) {
            $container->import('../config/graphql.php');
        }
        if (!TypedArray::bool($config, 'csrf_protection')) {
            $builder->getDefinition(UploadController::class)->replaceArgument(4, null);
            if ($builder->hasDefinition(PresignedUploadType::class)) {
                $builder->getDefinition(PresignedUploadType::class)->replaceArgument(6, null);
            }
        }
        if (class_exists(Command::class)) {
            $container->import('../config/console.php');
        }
        if (interface_exists(MessageBusInterface::class)) {
            $container->import('../config/messenger.php');
        }
        $webhook = TypedArray::array($config, 'webhook');
        $providers = array_filter(array_keys(self::WEBHOOK_TYPES), static fn (string $provider): bool => null !== TypedArray::nullableString(TypedArray::array($webhook, $provider), 'secret'));
        if ([] !== $providers) {
            if (!self::webhookAvailable()) {
                throw new LogicException('Storage webhooks require the Webhook component, try running "composer require symfony/webhook symfony/remote-event symfony/messenger".');
            }
            $container->import('../config/webhook.php');
            $consumer = $builder->getDefinition('vadage_presigned_uploader.webhook.consumer');
            foreach ($providers as $provider) {
                $consumer->addTag('remote_event.consumer', ['consumer' => self::WEBHOOK_TYPES[$provider]]);
            }
        }

        $repository = TypedArray::string(TypedArray::array($config, 'pending_upload'), 'repository');
        if ('doctrine' === $repository) {
            if (!$doctrine) {
                throw new LogicException('Pending uploads are stored with Doctrine ORM by default, try running "composer require doctrine/orm doctrine/doctrine-bundle" or configure "pending_upload.repository".');
            }
            $repository = 'vadage_presigned_uploader.repository.doctrine';
        }
        $builder->setAlias(PendingUploadRepositoryInterface::class, $repository);

        $buckets = $uploads = $objects = [];
        $storages = TypedArray::map($config, 'storages');
        foreach ($storages as $name => $storage) {
            $publicUrl = TypedArray::nullableString($storage, 'public_url');
            if (null !== $client = TypedArray::nullableString($storage, 'client')) {
                $buckets[$name] = TypedArray::string($storage, 'bucket');
                $builder->register($id = 'vadage_presigned_uploader.storage.'.$name, S3Storage::class)
                    ->setArguments([
                        $name,
                        new Reference($client),
                        $buckets[$name],
                        new Reference(TypedArray::nullableString($storage, 'credential_provider') ?? 'vadage_presigned_uploader.credential_provider'),
                        new Reference('clock'),
                        $publicUrl,
                        TypedArray::bool($storage, 'conditional_put'),
                    ]);
                $uploads[$name] = $objects[$name] = new Reference($id);
            }
            if (null !== $filesystem = TypedArray::nullableString($storage, 'filesystem')) {
                if (!interface_exists(FilesystemOperator::class)) {
                    throw new LogicException(\sprintf('Storage "%s" uses a filesystem, try running "composer require league/flysystem-bundle".', $name));
                }
                $builder->register($id = 'vadage_presigned_uploader.object_store.'.$name, FlysystemObjectStore::class)
                    ->setArguments([$name, new Reference($filesystem), new Reference('clock'), $publicUrl]);
                $objects[$name] = new Reference($id);
            }
        }
        $builder->getDefinition('vadage_presigned_uploader.storage_registry')->setArguments([
            new ServiceLocatorArgument($uploads),
            new ServiceLocatorArgument($objects),
            $buckets,
        ]);

        $defaults = TypedArray::array($config, 'defaults');
        $builder->setParameter('vadage_presigned_uploader.claim_ttl', TypedArray::int($defaults, 'claim_ttl'));
        $builder->setParameter('vadage_presigned_uploader.claim_lease', TypedArray::int($defaults, 'claim_lease'));
        // Build parameters (leading dot) are removed after compilation.
        $builder->setParameter('.vadage_presigned_uploader.storages', $storages);
        $builder->setParameter('.vadage_presigned_uploader.defaults', $defaults);
        $builder->setParameter('.vadage_presigned_uploader.mapped_classes', TypedArray::stringList($config, 'mapped_classes'));

        $builder->registerAttributeForAutoconfiguration(Uploadable::class, static function (ChildDefinition $definition): void {
            // Entities and DTOs are no services, keep them as tagged resources only.
            $definition->addResourceTag(self::UPLOADABLE_TAG);
        });
    }

    /**
     * An option of "pending_upload" as configured so far (prependExtension runs before processing).
     */
    private function pendingUploadOption(ContainerBuilder $builder, string $option, ?string $default): ?string
    {
        foreach ($builder->getExtensionConfig($this->extensionAlias) as $config) {
            $pendingUpload = $config['pending_upload'] ?? null;
            if (\is_array($pendingUpload) && \is_string($pendingUpload[$option] ?? null)) {
                $default = $pendingUpload[$option];
            }
        }

        return $default;
    }

    /**
     * The application's default entity manager, or null for the implicit single one.
     *
     * Each configuration array is normalized separately, so a root-level "orm.mappings" always ends up in an
     * entity manager called "default", which does not exist when the application names its entity managers.
     */
    private function defaultEntityManager(ContainerBuilder $builder): ?string
    {
        $default = $first = null;
        foreach ($builder->getExtensionConfig('doctrine') as $config) {
            $orm = $config['orm'] ?? null;
            if (!\is_array($orm)) {
                continue;
            }
            if (\is_string($orm['default_entity_manager'] ?? null)) {
                $default = $orm['default_entity_manager'];
            }
            if (\is_array($orm['entity_managers'] ?? null) && [] !== $orm['entity_managers']) {
                $first ??= (string) array_key_first($orm['entity_managers']);
            }
        }

        // Without named entity managers, the root level is the default one.
        return null !== $first ? $default ?? $first : null;
    }

    /**
     * API Platform 5 with GraphQL: the type converter of API Platform 4 has no convertPhpType().
     */
    public static function graphQlAvailable(): bool
    {
        return interface_exists(TypeConverterInterface::class) && (new \ReflectionClass(TypeConverterInterface::class))->hasMethod('convertPhpType');
    }

    private static function webhookAvailable(): bool
    {
        return class_exists(WebhookController::class) && class_exists(RemoteEvent::class);
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MappingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }
}
