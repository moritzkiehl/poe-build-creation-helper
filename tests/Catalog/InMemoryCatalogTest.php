<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\CatalogPort;
use App\Catalog\InMemoryCatalog;
use PHPUnit\Framework\TestCase;

final class InMemoryCatalogTest extends TestCase
{
    use CatalogPortContract;

    public function testAnEmptyCatalogSaysSoRatherThanPretendingNothingExists(): void
    {
        $catalog = new InMemoryCatalog();

        self::assertFalse($catalog->isAvailable());
        self::assertNull($catalog->state());
    }

    private function catalog(): CatalogPort
    {
        return InMemoryCatalog::withFixtures();
    }
}
