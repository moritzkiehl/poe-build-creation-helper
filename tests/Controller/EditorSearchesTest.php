<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\EditorSearches;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class EditorSearchesTest extends KernelTestCase
{
    public function testEveryTermIsEmptyWithoutARequest(): void
    {
        self::bootKernel();
        $searches = self::getContainer()->get(EditorSearches::class);

        $all = $searches->all();

        foreach (['passiveQuery', 'gemQuery', 'supportQuery', 'uniqueQuery', 'instilledQuery'] as $key) {
            self::assertSame('', $all[$key], $key.' must not fatal without a request');
        }
        foreach (['passiveResults', 'gemResults', 'supportResults', 'uniqueResults', 'instilledResults'] as $key) {
            self::assertSame([], $all[$key], $key.' must not query on an empty term');
        }
    }

    public function testATermIsReadFromTheQueryStringAndTrimmed(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/b/x/edit/y?support=%20%20Fast%20Forward%20%20'));

        self::assertSame('Fast Forward', self::getContainer()->get(EditorSearches::class)->all()['supportQuery']);
    }

    public function testATermFallsBackToTheRequestBodyForAnEditPost(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $stack->push(Request::create('/b/x/edit/y/act', 'POST', ['instilled' => 'Molten']));

        self::assertSame('Molten', self::getContainer()->get(EditorSearches::class)->all()['instilledQuery']);
    }

    public function testAnEmptyQueryStringTermBeatsAStaleBodyTerm(): void
    {
        self::bootKernel();
        $stack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/b/x/edit/y?q=', 'POST', ['q' => 'stale']);
        $stack->push($request);

        self::assertSame('', self::getContainer()->get(EditorSearches::class)->all()['passiveQuery'], 'clearing the box must clear the term');
    }
}
