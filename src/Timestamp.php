<?php

declare(strict_types=1);

namespace IngestVault;

/**
 * @internal
 */
final class Timestamp
{
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $value);

        return $parsed === false ? null : $parsed;
    }
}
