<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class ParsedRequirements
{
    /**
     * @param list<string> $requires canonical terms the supported skill must carry
     * @param list<string> $excludes canonical terms that rule the support out
     * @param list<string> $unparsed clause text no term could be read from
     * @param bool         $complete whether every clause found yielded a term
     */
    public function __construct(
        public array $requires = [],
        public array $excludes = [],
        public array $unparsed = [],
        public bool $complete = false,
    ) {
    }
}
