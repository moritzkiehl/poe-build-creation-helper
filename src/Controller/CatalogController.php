<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogPort;
use App\Catalog\CatalogSearch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    private const array KINDS = ['all', 'active', 'support', 'spirit', 'unique'];

    public function __construct(
        private readonly CatalogSearch $search,
        private readonly CatalogPort $catalog,
    ) {
    }

    #[Route('/catalog', name: 'app_catalog', methods: ['GET'])]
    public function browse(Request $request): Response
    {
        $kind = (string) $request->query->get('kind', 'all');
        if (!\in_array($kind, self::KINDS, true)) {
            $kind = 'all';
        }

        $query = trim((string) $request->query->get('q', ''));

        return $this->render('catalog/browse.html.twig', [
            'state' => $this->catalog->state(),
            'kinds' => self::KINDS,
            'kind' => $kind,
            'query' => $query,
            'counts' => $this->catalog->isAvailable() ? $this->search->counts() : [],
            'rows' => $this->catalog->isAvailable() ? $this->search->search('' === $query ? null : $query, $kind) : [],
        ]);
    }
}
