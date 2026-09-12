<?php

declare(strict_types=1);

namespace App\Build\Edit;

/**
 * An edit the format or the game would not accept. Thrown at the boundary —
 * everything reaching here came from a browser.
 */
final class InvalidEditCommand extends \RuntimeException
{
    public static function unknownAction(string $action): self
    {
        return new self(\sprintf('Unknown edit action "%s".', $action));
    }

    public static function outOfRange(string $field): self
    {
        return new self(\sprintf('%s is outside the range the game accepts.', $field));
    }

    public static function noSuchEntry(string $what): self
    {
        return new self(\sprintf('This build has no %s to change.', $what));
    }

    /**
     * For a field the build must always carry a value for — unlike
     * `noSuchEntry()`, the field is not unknown, it was just left empty.
     */
    public static function mustBeSet(string $what, string $why): self
    {
        return new self(\sprintf('%s must be set: %s.', $what, $why));
    }
}
