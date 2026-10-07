<?php

declare(strict_types=1);

namespace IngestVault;

/**
 * @internal
 */
final class Json
{
    private const WHITESPACE = " \t\n\r";

    /**
     * The text of one top-level member's value exactly as it appears in a JSON object, which json_decode()
     * cannot give back: it turns {} and [] into the same array, drops the zero fraction of 1.0 and turns
     * integers beyond PHP_INT_MAX into floats.
     */
    public static function member(string $json, string $key): ?string
    {
        $i = self::skipWhitespace($json, 0);
        if (self::char($json, $i) !== '{') {
            return null;
        }

        $i = self::skipWhitespace($json, $i + 1);
        if (self::char($json, $i) === '}') {
            return null;
        }

        $found = null;
        while (true) {
            $nameEnd = self::stringEnd($json, $i);
            $name = $nameEnd === null ? null : json_decode(substr($json, $i, $nameEnd - $i));
            if (! is_string($name)) {
                return null;
            }

            $i = self::skipWhitespace($json, $nameEnd);
            if (self::char($json, $i) !== ':') {
                return null;
            }

            $start = self::skipWhitespace($json, $i + 1);
            $end = self::valueEnd($json, $start);
            if ($end === null) {
                return null;
            }

            // The last occurrence wins, as it does for json_decode().
            if ($name === $key) {
                $found = substr($json, $start, $end - $start);
            }

            $i = self::skipWhitespace($json, $end);
            $char = self::char($json, $i);
            if ($char === '}') {
                return self::skipWhitespace($json, $i + 1) === strlen($json) ? $found : null;
            }
            if ($char !== ',') {
                return null;
            }

            $i = self::skipWhitespace($json, $i + 1);
        }
    }

    private static function valueEnd(string $json, int $start): ?int
    {
        $char = self::char($json, $start);

        if ($char === '"') {
            return self::stringEnd($json, $start);
        }

        if ($char === '{' || $char === '[') {
            $depth = 0;
            $length = strlen($json);
            for ($i = $start; $i < $length; $i++) {
                $i += strcspn($json, '"{}[]', $i);
                if ($i === $length) {
                    return null;
                }

                $char = $json[$i];
                if ($char === '"') {
                    do {
                        $i++;
                        $i += strcspn($json, '"\\', $i);
                        if ($i === $length) {
                            return null;
                        }
                    } while ($json[$i] === '\\' && ++$i < $length);
                } elseif ($char === '{' || $char === '[') {
                    $depth++;
                } elseif (--$depth === 0) {
                    return $i + 1;
                }
            }

            return null;
        }

        $length = strcspn($json, ',}]' . self::WHITESPACE, $start);

        return $length === 0 ? null : $start + $length;
    }

    private static function stringEnd(string $json, int $start): ?int
    {
        if (self::char($json, $start) !== '"') {
            return null;
        }

        $length = strlen($json);
        for ($i = $start + 1; $i < $length; $i += 2) {
            $i += strcspn($json, '"\\', $i);
            if ($i < $length && $json[$i] === '"') {
                return $i + 1;
            }
        }

        return null;
    }

    private static function skipWhitespace(string $json, int $i): int
    {
        return $i + strspn($json, self::WHITESPACE, $i);
    }

    private static function char(string $json, int $i): string
    {
        return $i < strlen($json) ? $json[$i] : '';
    }
}
