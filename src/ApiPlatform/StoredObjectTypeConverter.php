<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\ApiPlatform;

use ApiPlatform\GraphQl\Type\TypeConverterInterface;
use ApiPlatform\Metadata\GraphQl\Operation;
use GraphQL\Type\Definition\Type as GraphQLType;
use Symfony\Component\TypeInfo\Type;

/**
 * Exposes StoredObject properties in GraphQL: an upload id (String) in mutations, a StoredObject in queries.
 * Without it, API Platform leaves them out of the schema.
 *
 * @internal
 */
final readonly class StoredObjectTypeConverter implements TypeConverterInterface
{
    public function __construct(
        private TypeConverterInterface $decorated,
    ) {
    }

    public function convertPhpType(Type $type, bool $input, Operation $rootOperation, string $resourceClass, string $rootResource, ?string $property, int $depth): GraphQLType|string|null
    {
        if (!StoredObjectPropertyMetadataFactory::isStoredObject($type)) {
            return $this->decorated->convertPhpType($type, $input, $rootOperation, $resourceClass, $rootResource, $property, $depth);
        }

        // A registered type, by name: API Platform's type container holds the instance the schema uses.
        return $input ? GraphQLType::string() : StoredObjectGraphQlType::NAME;
    }

    public function resolveType(string $type): ?GraphQLType
    {
        return $this->decorated->resolveType($type);
    }
}
