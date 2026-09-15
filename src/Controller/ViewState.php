<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * Every query parameter that must survive an edit, in one list.
 *
 * A view-state key has to reach every place that carries the editor's state
 * across a request: the hidden fields every edit form posts, the redirect a
 * plain form POST follows, the header's field macro, the links that change one
 * view, the GET search forms, and the canvas's fetch URL. Each of those once
 * kept its own list, and a key missed in one was silently dropped — five times
 * before this registry existed. Every consumer reads this list now: no
 * template keeps its own hand-listed copy of the keys any more, they loop
 * over `viewState` (`_search_state.html.twig`) or merge it into a link map.
 */
final class ViewState
{
    public const array KEYS = ['q', 'gem', 'support', 'unique', 'instilled', 'intervals', 'supports', 'stats'];

    /**
     * Every key with its raw, trimmed term — '' when absent, never a resolved
     * default, so a view nobody chose never lands in a link.
     *
     * @return array<string, string>
     */
    public static function fromRequest(?Request $request): array
    {
        $state = [];

        foreach (self::KEYS as $key) {
            $state[$key] = self::term($request, $key);
        }

        return $state;
    }

    /**
     * A term normally arrives in the query string — the search forms submit
     * via GET. An edit is a POST carrying neither, so the redirect and the
     * Turbo response that follow it fall back to the request body, which the
     * `/act` forms carry the term in as a hidden field. The query string wins
     * when present, even empty, so clearing the search box still clears the
     * term instead of resurrecting it from a stale hidden field.
     */
    private static function term(?Request $request, string $key): string
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
