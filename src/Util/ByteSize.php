<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Util;

/**
 * Parses sizes like Symfony's File constraint: bytes, or a number with k, M, G (powers of 1000) or Ki, Mi, Gi
 * (powers of 1024), case-insensitive.
 *
 * @internal
 */
final class ByteSize
{
    /**
     * @throws \InvalidArgumentException
     */
    public static function parse(int|string $size): int
    {
        if (\is_int($size) || ctype_digit($size)) {
            $bytes = (int) $size;
        } elseif (1 === preg_match('/^(\d+)(k|ki|m|mi|g|gi)$/i', $size, $matches)) {
            $factors = ['k' => 1000, 'ki' => 1 << 10, 'm' => 1000 ** 2, 'mi' => 1 << 20, 'g' => 1000 ** 3, 'gi' => 1 << 30];
            $bytes = (int) $matches[1] * $factors[strtolower($matches[2])];
        } else {
            $bytes = -1;
        }

        return $bytes > 0 ? $bytes : throw new \InvalidArgumentException(\sprintf('"%s" is not a valid size.', $size));
    }
}
