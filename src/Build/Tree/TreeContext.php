<?php

declare(strict_types=1);

namespace App\Build\Tree;

/**
 * What legality depends on besides the tree itself.
 *
 * `jewelKeystoneId` is the keystone a declared keystone-radius jewel works
 * around (rule 4), set by the editor's `jewel.set` action; null when the build
 * declares no jewel.
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
