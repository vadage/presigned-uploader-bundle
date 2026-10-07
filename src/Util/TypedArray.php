<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Util;

/**
 * Reads values with a known shape from untyped arrays (processed configuration, database JSON).
 *
 * @internal
 */
final class TypedArray
{
    /**
     * @param array<mixed> $array
     */
    public static function string(array $array, string $key): string
    {
        return \is_string($array[$key] ?? null) ? $array[$key] : throw self::invalid($key, 'string');
    }

    /**
     * @param array<mixed> $array
     */
    public static function nullableString(array $array, string $key): ?string
    {
        $value = $array[$key] ?? null;

        return null === $value || \is_string($value) ? $value : throw self::invalid($key, '?string');
    }

    /**
     * @param array<mixed> $array
     */
    public static function int(array $array, string $key): int
    {
        return \is_int($array[$key] ?? null) ? $array[$key] : throw self::invalid($key, 'int');
    }

    /**
     * @param array<mixed> $array
     */
    public static function bool(array $array, string $key): bool
    {
        return \is_bool($array[$key] ?? null) ? $array[$key] : throw self::invalid($key, 'bool');
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<mixed>
     */
    public static function array(array $array, string $key): array
    {
        return \is_array($array[$key] ?? null) ? $array[$key] : throw self::invalid($key, 'array');
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, array<mixed>>
     */
    public static function map(array $array, string $key): array
    {
        $map = [];
        foreach (self::array($array, $key) as $name => $value) {
            $map[\is_string($name) ? $name : throw self::invalid($key, 'map')] = \is_array($value) ? $value : throw self::invalid($key, 'map');
        }

        return $map;
    }

    /**
     * @param array<mixed> $array
     *
     * @return list<string>
     */
    public static function stringList(array $array, string $key): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => \is_string($value) ? $value : throw self::invalid($key, 'list<string>'),
            self::array($array, $key),
        ));
    }

    private static function invalid(string $key, string $type): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('Expected "%s" to be of type %s.', $key, $type));
    }
}
