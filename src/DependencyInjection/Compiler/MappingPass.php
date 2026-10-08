<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ExpressionLanguage\Expression;
use Vadage\PresignedUploaderBundle\Attribute\Uploadable;
use Vadage\PresignedUploaderBundle\Attribute\UploadableField;
use Vadage\PresignedUploaderBundle\Mapping\StagingConfig;
use Vadage\PresignedUploaderBundle\Mapping\UploadMapping;
use Vadage\PresignedUploaderBundle\Naming\NamerInterface;
use Vadage\PresignedUploaderBundle\Staging\PromoterInterface;
use Vadage\PresignedUploaderBundle\Util\ByteSize;
use Vadage\PresignedUploaderBundle\Util\TypedArray;
use Vadage\PresignedUploaderBundle\VadagePresignedUploaderBundle;
use Vadage\PresignedUploaderBundle\Validator\PresignedFile;

/**
 * Collects #[UploadableField] mappings and validates them, so misconfigurations fail at build time.
 *
 * @internal
 */
final class MappingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('vadage_presigned_uploader.mapping_registry')) {
            return;
        }

        $config = [];
        foreach (['storages', 'defaults', 'mapped_classes'] as $key) {
            $config[$key] = $container->getParameter('.vadage_presigned_uploader.'.$key);
        }
        $storages = TypedArray::map($config, 'storages');
        $defaults = TypedArray::array($config, 'defaults');
        $staging = $this->staging($storages);
        $services = [];

        $mappings = $seen = [];
        foreach ($this->classes($container, TypedArray::stringList($config, 'mapped_classes')) as $class) {
            foreach ($this->properties($class) as $property) {
                // A property declared on a parent is mapped once, for the parent and all of its subclasses.
                $declaringClass = $property->getDeclaringClass()->getName();
                if (isset($seen[$declaringClass.'::'.$property->getName()])) {
                    continue;
                }
                $seen[$declaringClass.'::'.$property->getName()] = true;

                foreach ($property->getAttributes(UploadableField::class) as $attribute) {
                    $field = $attribute->newInstance();
                    $context = \sprintf('#[UploadableField(name: "%s")] on %s::$%s', $field->name, $class->getName(), $property->getName());

                    if (isset($mappings[$field->name])) {
                        throw new InvalidArgumentException($context.': the name is already used.');
                    }
                    if (1 !== preg_match('/^[A-Za-z0-9_.-]+$/', $field->name)) {
                        throw new InvalidArgumentException($context.': the name may only contain letters, digits, "_", "." and "-".');
                    }
                    if (!isset($storages[$field->storage])) {
                        throw new InvalidArgumentException(\sprintf('%s: storage "%s" is not configured.', $context, $field->storage));
                    }
                    if (true === $field->staging && null === $staging[$field->storage]) {
                        throw new InvalidArgumentException(\sprintf('%s: storage "%s" has no staging configured.', $context, $field->storage));
                    }
                    $namer = $field->namer ?? TypedArray::string($defaults, 'namer');
                    $uploadTtl = $field->uploadTtl ?? TypedArray::int($defaults, 'upload_ttl');
                    $stagingConfig = $maxSize = null;
                    if ($field->uploads) {
                        $services[NamerInterface::class][$namer] = $this->service($container, $namer, NamerInterface::class, $context);

                        $stagingConfig = false === $field->staging ? null : $staging[$field->storage];
                        $claimTtl = TypedArray::int($defaults, 'claim_ttl');
                        // Expired uploads are deleted once; a presigned PUT outliving them would recreate an untracked object.
                        if ($uploadTtl < 1 || $uploadTtl > VadagePresignedUploaderBundle::MAX_UPLOAD_TTL || $uploadTtl >= $claimTtl) {
                            throw new InvalidArgumentException(\sprintf('%s: uploadTtl must be between 1 and %d seconds and shorter than "defaults.claim_ttl" (%d).', $context, VadagePresignedUploaderBundle::MAX_UPLOAD_TTL, $claimTtl));
                        }
                        // Without "If-None-Match", the presigned URL can overwrite the verified object until it expires.
                        $uploadStorage = $stagingConfig?->getArgument(0) ?? $field->storage;
                        \assert(\is_string($uploadStorage));
                        if (null === TypedArray::nullableString($storages[$uploadStorage], 'client')) {
                            throw new InvalidArgumentException(\sprintf('%s: storage "%s" has no "client", it cannot receive uploads. Configure one, or stage uploads in a storage that has.', $context, $uploadStorage));
                        }
                        if (null !== $stagingConfig) {
                            $promoter = $stagingConfig->getArgument(2);
                            \assert(\is_string($promoter));
                            $services[PromoterInterface::class][$promoter] = $this->service($container, $promoter, PromoterInterface::class, $context);
                        }
                        if (!$field->checksum && !TypedArray::bool($storages[$uploadStorage], 'conditional_put')) {
                            throw new InvalidArgumentException(\sprintf('%s: storage "%s" has "conditional_put" disabled, which requires "checksum: true".', $context, $uploadStorage));
                        }

                        $maxSize = $this->maxSize($defaults, $property, $context);
                    }
                    if (null !== $field->security) {
                        $this->checkSecurity($container, $field, $context);
                    }

                    $mappings[$field->name] = new Definition(UploadMapping::class, [
                        $field->name,
                        $declaringClass,
                        $property->getName(),
                        $field->storage,
                        $namer,
                        $field->prefix,
                        $field->checksum,
                        $stagingConfig,
                        $field->sniffContent ?? TypedArray::bool($defaults, 'sniff_content'),
                        $uploadTtl,
                        $field->deleteOnRemove,
                        $field->deleteOnReplace,
                        $maxSize,
                        $field->uploads,
                        $field->security,
                    ]);
                }
            }
        }

        $container->getDefinition('vadage_presigned_uploader.mapping_registry')->replaceArgument(0, array_values($mappings));
        // Exactly the namers and promoters the mappings use, whether or not they are tagged.
        $container->getDefinition('vadage_presigned_uploader.upload_manager')
            ->replaceArgument(6, new ServiceLocatorArgument($services[NamerInterface::class] ?? []))
            ->replaceArgument(7, new ServiceLocatorArgument($services[PromoterInterface::class] ?? []));
    }

    private function checkSecurity(ContainerBuilder $container, UploadableField $field, string $context): void
    {
        if (!$field->uploads) {
            throw new InvalidArgumentException($context.': "security" applies to presigning, which "uploads: false" turns off.');
        }
        if (!class_exists(Expression::class)) {
            throw new LogicException($context.': "security" requires the ExpressionLanguage component, try running "composer require symfony/expression-language".');
        }
        if (!$container->has('security.authorization_checker')) {
            throw new LogicException($context.': "security" requires the SecurityBundle, try running "composer require symfony/security-bundle".');
        }
    }

    /**
     * Every mapping needs an upper bound: without one, anyone allowed to presign could upload up to 5 GiB.
     * #[PresignedFile(maxSize: ...)] sets it; "defaults.max_size" covers the mappings without (e.g. with
     * constraints mapped in YAML or XML, which are not visible here).
     *
     * @param array<mixed> $defaults
     *
     * @return int|null null when the mapping's constraints limit the size
     */
    private function maxSize(array $defaults, \ReflectionProperty $property, string $context): ?int
    {
        foreach ($property->getAttributes(PresignedFile::class) as $attribute) {
            $constraint = $attribute->newInstance();
            if (null !== $constraint->maxSize && \in_array(PresignedFile::PRESIGN_GROUP, $constraint->groups ?? [], true)) {
                return null;
            }
        }

        $default = $defaults['max_size'] ?? null;
        if (\is_int($default) || \is_string($default)) {
            return ByteSize::parse($default);
        }

        throw new InvalidArgumentException(\sprintf('%s: no maximum size. Add #[PresignedFile(maxSize: ...)] or configure "defaults.max_size".', $context));
    }

    /**
     * @param class-string $interface
     */
    private function service(ContainerBuilder $container, string $id, string $interface, string $context): Reference
    {
        $class = $container->has($id) ? $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass()) : null;
        if (!\is_string($class) || !is_a($class, $interface, true)) {
            throw new InvalidArgumentException(\sprintf('%s: service "%s" does not exist or does not implement %s.', $context, $id, $interface));
        }

        return new Reference($id);
    }

    /**
     * @param list<string> $mappedClasses
     *
     * @return list<\ReflectionClass<object>>
     */
    private function classes(ContainerBuilder $container, array $mappedClasses): array
    {
        foreach ($container->getDefinitions() as $definition) {
            if ($definition->hasTag(VadagePresignedUploaderBundle::UPLOADABLE_TAG) && null !== $class = $definition->getClass()) {
                $mappedClasses[] = $class;
            }
        }

        $classes = [];
        foreach (array_unique($mappedClasses) as $class) {
            $class = $container->getParameterBag()->resolveValue($class);
            $reflection = \is_string($class) ? $container->getReflectionClass($class) : null;
            if (null === $reflection || [] === $reflection->getAttributes(Uploadable::class)) {
                throw new InvalidArgumentException(\sprintf('Class "%s" is listed as mapped class but has no #[Uploadable] attribute.', \is_string($class) ? $class : get_debug_type($class)));
            }
            $classes[] = $reflection;
        }

        return $classes;
    }

    /**
     * @param \ReflectionClass<object> $class
     *
     * @return iterable<\ReflectionProperty> including private properties of parent classes
     */
    private function properties(\ReflectionClass $class): iterable
    {
        $seen = [];
        for ($current = $class; false !== $current; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if (!isset($seen[$property->getName()])) {
                    $seen[$property->getName()] = true;
                    yield $property;
                }
            }
        }
    }

    /**
     * @param array<string, array<mixed>> $storages
     *
     * @return array<string, Definition|null> storage name => StagingConfig definition
     */
    private function staging(array $storages): array
    {
        $result = [];
        foreach ($storages as $name => $storage) {
            $staging = TypedArray::array($storage, 'staging');
            if (!TypedArray::bool($staging, 'enabled')) {
                $result[$name] = null;
                continue;
            }

            $stagingStorage = TypedArray::nullableString($staging, 'storage') ?? throw new InvalidArgumentException(\sprintf('Storage "%s": "staging.storage" is required.', $name));
            $prefix = TypedArray::string($staging, 'prefix');
            $promoter = TypedArray::string($staging, 'promoter');

            if (!isset($storages[$stagingStorage])) {
                throw new InvalidArgumentException(\sprintf('Storage "%s": staging storage "%s" is not configured.', $name, $stagingStorage));
            }
            if ($stagingStorage === $name && '' === $prefix) {
                throw new InvalidArgumentException(\sprintf('Storage "%s": staging in the same storage requires a "staging.prefix".', $name));
            }
            if ($stagingStorage !== $name && TypedArray::bool(TypedArray::array($storages[$stagingStorage], 'staging'), 'enabled')) {
                throw new InvalidArgumentException(\sprintf('Storage "%s": staging storage "%s" must not have staging itself.', $name, $stagingStorage));
            }

            $result[$name] = new Definition(StagingConfig::class, [$stagingStorage, $prefix, $promoter]);
        }

        return $result;
    }
}
