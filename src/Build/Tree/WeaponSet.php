<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * Which weapon set a passive belongs to.
 *
 * The `.build` format writes 1 or 2 and omits the key entirely for a passive
 * that applies to both. The value 0 is documented as valid but appears in no
 * file the game has written, so it is read and never produced.
 */
enum WeaponSet: int
{
    case Shared = 0;
    case One = 1;
    case Two = 2;

    public static function fromWire(mixed $raw): self
    {
        return match (true) {
            1 === $raw, '1' === $raw => self::One,
            2 === $raw, '2' === $raw => self::Two,
            default => self::Shared,
        };
    }

    /**
     * Null means "write no key at all", which is what shared looks like.
     */
    public function toWire(): ?int
    {
        return self::Shared === $this ? null : $this->value;
    }
}
