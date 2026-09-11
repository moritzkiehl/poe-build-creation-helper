<?php

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogPort;
use App\Catalog\CatalogSearch;
use App\Catalog\TreeExport;
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
        private readonly TreeExport $tree,
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

    /**
     * Cached against the sync rather than a fixed lifetime: the tree changes
     * when we adopt a patch and at no other time.
     */
    #[Route('/catalog/tree.json', name: 'app_catalog_tree', methods: ['GET'])]
    public function treeData(Request $request): Response
    {
        $revision = $this->tree->revision();
        $response = new Response();
        $response->setPublic();

        if (null !== $revision) {
            $response->setLastModified($revision);
            $response->setEtag(md5($revision->format(\DATE_ATOM)));

            if ($response->isNotModified($request)) {
                return $response;
            }
        }

        $payload = null === $revision ? ['nodes' => [], 'edges' => [], 'classes' => []] : $this->tree->payload();
        $response->setContent(json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }
}
