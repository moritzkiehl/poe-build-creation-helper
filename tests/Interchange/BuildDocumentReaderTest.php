<?php

declare(strict_types=1);

namespace App\Tests\Interchange;

use App\Interchange\BuildDocumentReader;
use App\Interchange\InvalidBuildDocument;
use PHPUnit\Framework\TestCase;

final class BuildDocumentReaderTest extends TestCase
{
    public function testReadsTheBuildName(): void
    {
        $document = new BuildDocumentReader()->read('{"name":"Titan Earthquake Slam"}');

        self::assertSame('Titan Earthquake Slam', $document->name);
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(InvalidBuildDocument::class);
        $this->expectExceptionMessage('not valid JSON');

        new BuildDocumentReader()->read('{"name": ');
    }

    public function testRejectsJsonThatIsNotAnObject(): void
    {
        $this->expectException(InvalidBuildDocument::class);
        $this->expectExceptionMessage('must be a JSON object');

        new BuildDocumentReader()->read('["Titan Earthquake Slam"]');
    }

    public function testRejectsADocumentWithoutAName(): void
    {
        $this->expectException(InvalidBuildDocument::class);
        $this->expectExceptionMessage('name');

        new BuildDocumentReader()->read('{"ascendancy": "Titan"}');
    }
}
