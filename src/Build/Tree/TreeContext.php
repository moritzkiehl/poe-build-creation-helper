<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * What legality depends on besides the tree itself.
 *
 * `jewelKeystoneId` is always null in slice B1: the mechanism is here because
 * rules 3 and 4 differ only in what enables a keystone, but the control that
 * declares it belongs to slice B2.
 */
final readonly class TreeContext
{
    public function __construct(
        public ?string $startNodeId,
        public ?string $ascendancyKey,
        public ?string $jewelKeystoneId = null,
    ) {
    }
}
