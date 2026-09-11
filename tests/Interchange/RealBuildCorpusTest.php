<?php

declare(strict_types=1);

namespace App\Tests\Interchange;

use App\Interchange\BuildDocumentReader;
use App\Interchange\BuildDocumentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against real files exported by Path of Exile 2, if any are lying in
 * var/sample. They are never committed: they are someone's build documents and
 * they carry GGG identifiers, and the repository is public. Drop a few in that
 * directory and this suite starts checking the reader against reality instead
 * of against a fixture we wrote ourselves.
 */
final class RealBuildCorpusTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function realBuildFiles(): iterable
    {
        foreach (glob(__DIR__.'/../../var/sample/*.build') ?: [] as $file) {
            yield basename($file) => [$file];
        }
    }

    #[DataProvider('realBuildFiles')]
    public function testAGameWrittenFileSurvivesTheRoundTripUnchanged(string $file): void
    {
        $json = file_get_contents($file);
        self::assertIsString($json);

        $written = new BuildDocumentWriter()->write(new BuildDocumentReader()->read($json));

        self::assertSame(json_decode($json, true), json_decode($written, true));
    }

    public function testTheCorpusIsOptional(): void
    {
        $count = iterator_count(self::realBuildFiles());

        if (0 === $count) {
            self::markTestSkipped('No sample builds in var/sample — the corpus is optional.');
        }

        self::assertGreaterThan(0, $count);
    }
}
