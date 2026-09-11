<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * @phpstan-type Gem array{id: string, name: string, kind: string, primary_attribute: string|null, icon: string|null}
 * @phpstan-type GemTag array{gem_id: string, tag: string}
 * @phpstan-type Recommended array{gem_id: string, support_id: string, rank: int}
 */
final readonly class NormalizedGems
{
    /**
     * @param list<Gem>                             $gems
     * @param list<GemTag>                          $tags
     * @param list<Recommended>                     $recommendedSupports
     * @param list<array{id: string, text: string}> $supportTexts
     */
    public function __construct(
        public array $gems,
        public array $tags,
        public array $recommendedSupports,
        public array $supportTexts = [],
    ) {
    }
}
