<?php

declare(strict_types=1);

namespace App\Interchange;

/**
 * Every way a `.build` document can be unusable. The reader throws this and
 * nothing else, so callers never have to catch JsonException or a TypeError.
 */
final class InvalidBuildDocument extends \RuntimeException
{
    public static function notJson(\JsonException $previous): self
    {
        return new self('The file is not valid JSON: '.$previous->getMessage(), previous: $previous);
    }

    public static function notAnObject(): self
    {
        return new self('A build must be a JSON object.');
    }

    public static function missingField(string $field): self
    {
        return new self(sprintf('Required field "%s" is missing.', $field));
    }

    public static function wrongType(string $field, string $expected, string $actual): self
    {
        return new self(sprintf('Field "%s" must be %s, got %s.', $field, $expected, $actual));
    }
}
