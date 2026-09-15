<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ViewState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ViewStateTest extends TestCase
{
    public function testEveryKeyIsPresentAndEmptyWithoutARequest(): void
    {
        // Hand-written, not array_fill_keys(ViewState::KEYS, ''): dropping a key
        // from the registry must fail here, not shrink both sides together.
        self::assertSame(
            ['q' => '', 'gem' => '', 'support' => '', 'unique' => '', 'instilled' => '', 'intervals' => '', 'supports' => '', 'stats' => ''],
            ViewState::fromRequest(null),
        );
    }

    public function testTheQueryStringWinsEvenWhenEmptyAndTheBodyFillsTheRest(): void
    {
        $state = ViewState::fromRequest(new Request(query: ['stats' => ''], request: ['stats' => '1', 'gem' => '  Fireball ']));

        self::assertSame('', $state['stats'], 'clearing a term in the URL must beat a stale hidden field');
        self::assertSame('Fireball', $state['gem']);
    }
}
