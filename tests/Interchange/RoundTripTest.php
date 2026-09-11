<?php

declare(strict_types=1);

namespace App\Tests\Interchange;

use App\Interchange\BuildDocumentReader;
use App\Interchange\BuildDocumentWriter;
use PHPUnit\Framework\TestCase;

final class RoundTripTest extends TestCase
{
    /**
     * The acceptance condition of iteration 1: whatever the game wrote, we hand
     * back unchanged. Compared as decoded data, because key order is not part of
     * the contract.
     */
    public function testReadingAndWritingPreservesTheWholeDocument(): void
    {
        $json = file_get_contents(__DIR__.'/../fixtures/build/valid-full.build');
        self::assertIsString($json);

        $written = new BuildDocumentWriter()->write(new BuildDocumentReader()->read($json));

        self::assertSame(json_decode($json, true), json_decode($written, true));
    }
}
