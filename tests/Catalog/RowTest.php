<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Row;
use PHPUnit\Framework\TestCase;

final class RowTest extends TestCase
{
    public function testJsonStringsReadsAValidList(): void
    {
        self::assertSame(['a', 'b'], Row::jsonStrings(['tags' => '["a", "b"]'], 'tags'));
    }

    public function testJsonStringsDropsWhateverIsNotAString(): void
    {
        self::assertSame(['a'], Row::jsonStrings(['tags' => '["a", 1, null, {"x": 1}]'], 'tags'));
    }

    public function testJsonStringsFallsBackToAnEmptyListOnAMissingKey(): void
    {
        self::assertSame([], Row::jsonStrings([], 'tags'));
    }
}
