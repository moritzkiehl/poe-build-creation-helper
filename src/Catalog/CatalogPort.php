<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\View\CatalogState;
use App\Catalog\View\GemInfo;
use App\Catalog\View\PassiveInfo;
use App\Catalog\View\RequirementInfo;
use App\Catalog\View\UniqueInfo;

/**
 * Everything the rules engine may ask the catalog, and nothing more.
 *
 * This exists so `Advice` can be tested against a fake instead of a database —
 * it is the part of the system that gets readjusted with every game patch, so it
 * has to be cheap to exercise. It also keeps the catalog from being load-bearing:
 * an implementation that knows nothing is a valid implementation, and the app
 * still opens, edits, shares and exports builds.
 */
interface CatalogPort
{
    /**
     * False when no catalog data has been synced. Rules answer with a single
     * hint rather than a page of false findings.
     */
    public function isAvailable(): bool;

    public function state(): ?CatalogState;

    public function passive(string $id): ?PassiveInfo;

    /**
     * @return list<string> ids reachable from this passive in either direction
     */
    public function neighbours(string $id): array;

    public function gem(string $id): ?GemInfo;

    /**
     * @return list<RequirementInfo>
     */
    public function supportRequirements(string $supportId): array;

    /**
     * @return list<string> support ids, best first
     */
    public function recommendedSupports(string $gemId): array;

    /**
     * Several uniques can share a name, and `.build` identifies them by name, so
     * this never answers with a single item.
     *
     * @return list<UniqueInfo>
     */
    public function uniquesNamed(string $name): array;
}
