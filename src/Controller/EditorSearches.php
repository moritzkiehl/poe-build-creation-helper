<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogSearch;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every search the editor offers, and the terms that drive them.
 *
 * Extracted from EditorContext once there were five: a context builder that
 * also coordinates searching is doing two jobs, and the searches share one
 * awkward rule about where a term comes from.
 *
 * @phpstan-import-type ResultRow from CatalogSearch
 */
final class EditorSearches
{
    public function __construct(
        private readonly CatalogSearch $search,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return array{
     *     viewState: array<string, string>,
     *     passiveQuery: string,
     *     passiveResults: list<array{id: string, name: string, kind: string, stats: list<string>}>,
     *     gemQuery: string,
     *     gemResults: list<ResultRow>,
     *     supportQuery: string,
     *     supportResults: list<ResultRow>,
     *     uniqueQuery: string,
     *     uniqueResults: list<ResultRow>,
     *     instilledQuery: string,
     *     instilledResults: list<array{id: string, name: string, kind: string, stats: list<string>, recipe: list<string>}>,
     *     intervalsOverride: string,
     *     supportsOverride: string,
     *     statsOverride: string,
     * }
     */
    public function all(): array
    {
        $state = ViewState::fromRequest($this->requests->getCurrentRequest());

        $passive = $state['q'];
        $gem = $state['gem'];
        $support = $state['support'];
        $unique = $state['unique'];
        $instilled = $state['instilled'];

        return [
            'viewState' => $state,
            'passiveQuery' => $passive,
            'passiveResults' => '' !== $passive ? $this->search->passives($passive) : [],
            'gemQuery' => $gem,
            'gemResults' => '' !== $gem ? $this->search->search($gem, 'all') : [],
            'supportQuery' => $support,
            'supportResults' => '' !== $support ? $this->search->search($support, 'support') : [],
            'uniqueQuery' => $unique,
            'uniqueResults' => '' !== $unique ? $this->search->search($unique, 'unique') : [],
            'instilledQuery' => $instilled,
            'instilledResults' => '' !== $instilled ? $this->search->instillablePassives($instilled) : [],
            'intervalsOverride' => $state['intervals'],
            'supportsOverride' => $state['supports'],
            'statsOverride' => $state['stats'],
        ];
    }
}
