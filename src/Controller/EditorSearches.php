<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogSearch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Every search the editor offers, and the terms that drive them.
 *
 * Extracted from EditorContext once there were five: a context builder that
 * also coordinates searching is doing two jobs, and the searches share one
 * awkward rule about where a term comes from.
 */
final class EditorSearches
{
    public function __construct(
        private readonly CatalogSearch $search,
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $request = $this->requests->getCurrentRequest();

        $passive = $this->term($request, 'q');
        $gem = $this->term($request, 'gem');
        $support = $this->term($request, 'support');
        $unique = $this->term($request, 'unique');
        $instilled = $this->term($request, 'instilled');

        // Neither view-state override is a search term, but each survives an
        // edit the same way a search term does — as a query parameter that
        // wins over a stale hidden field from the request body — so it is
        // read with the same nullsafe helper rather than inventing a second
        // idiom for the same problem.
        $intervalsOverride = $this->term($request, 'intervals');
        $supportsOverride = $this->term($request, 'supports');

        return [
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
            'intervalsOverride' => $intervalsOverride,
            'supportsOverride' => $supportsOverride,
        ];
    }

    /**
     * A search term normally arrives in the query string — the search forms
     * submit via GET. An edit is a POST carrying neither, so the redirect and
     * the Turbo response that follow it fall back to the request body, which
     * the `/act` forms carry the term in as a hidden field. The query string
     * wins when present, even empty, so clearing the search box still clears
     * the term instead of resurrecting it from a stale hidden field.
     */
    private function term(?Request $request, string $key): string
    {
        if (null === $request) {
            return '';
        }

        $value = $request->query->get($key);

        if (!\is_string($value)) {
            $value = $request->request->get($key);
        }

        return trim(\is_string($value) ? $value : '');
    }
}
