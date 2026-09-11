<?php

declare(strict_types=1);

namespace App\Tests\Build;

use App\Build\TokenGenerator;
use PHPUnit\Framework\TestCase;

final class TokenGeneratorTest extends TestCase
{
    public function testShareSlugIsUrlSafeAndLongEnoughToBeUnguessable(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-zA-Z]{22}$/', new TokenGenerator()->shareSlug());
    }

    public function testEverySlugIsDifferent(): void
    {
        $generator = new TokenGenerator();
        $slugs = [];
        for ($i = 0; $i < 1000; ++$i) {
            $slugs[] = $generator->shareSlug();
        }

        self::assertCount(1000, array_unique($slugs));
    }

    public function testTheEditTokenIsStoredHashedAndNeverInClear(): void
    {
        $generator = new TokenGenerator();
        $token = $generator->editToken();

        $hash = $generator->hash($token);

        self::assertStringNotContainsString($token, $hash);
        self::assertTrue($generator->verify($token, $hash));
    }

    public function testAWrongEditTokenIsRejected(): void
    {
        $generator = new TokenGenerator();

        self::assertFalse($generator->verify($generator->editToken(), $generator->hash($generator->editToken())));
    }
}
