<?php

declare(strict_types=1);

namespace App\Catalog\View;

/**
 * `origin` decides how loudly a rule may speak: a parsed requirement is right
 * about 85% of the time and may only produce warnings, a curated one carries
 * whatever severity its YAML entry states.
 */
final readonly class RequirementInfo
{
    public function __construct(
        public string $term,
        public string $mode,
        public string $origin,
        public ?string $clause = null,
    ) {
    }
}
