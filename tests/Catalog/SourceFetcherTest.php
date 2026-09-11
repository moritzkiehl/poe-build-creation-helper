<?php

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\SourceFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SourceFetcherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/catalog'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testItIdentifiesItselfWithAContactAddress(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = self::headersOf($options);

            return new MockResponse('{}');
        });

        $this->fetcher($client)->fetch('tree', 'https://example.test/data.json');

        self::assertArrayHasKey('user-agent', $seen);
        self::assertStringContainsString('contact:', implode('', $seen['user-agent']));
    }

    public function testItStoresTheBodyAndReportsTheRevisionUpstreamGave(): void
    {
        $client = new MockHttpClient(new MockResponse('{"tree":"Default"}', ['response_headers' => ['Last-Modified' => 'Mon, 07 Sep 2026 10:00:00 GMT']]));

        $result = $this->fetcher($client)->fetch('tree', 'https://example.test/data.json');

        self::assertTrue($result->changed);
        self::assertSame('Mon, 07 Sep 2026 10:00:00 GMT', $result->revision);
        self::assertSame('{"tree":"Default"}', file_get_contents($this->dir.'/tree.json'));
    }

    /**
     * raw.githubusercontent.com sends an ETag and no Last-Modified, so a fetcher
     * that only knows If-Modified-Since re-downloads five megabytes on every
     * run. Verified against the live host on 2026-09-11.
     */
    public function testAnEtagIsPreferredAsTheRevision(): void
    {
        $client = new MockHttpClient(new MockResponse('{"tree":"Default"}', ['response_headers' => [
            'ETag' => '"1b2cd3e55be37e446f8f476a473152023ac4e0cd"',
            'Last-Modified' => 'Mon, 07 Sep 2026 10:00:00 GMT',
        ]]));

        $result = $this->fetcher($client)->fetch('tree', 'https://example.test/data.json');

        self::assertSame('"1b2cd3e55be37e446f8f476a473152023ac4e0cd"', $result->revision);
    }

    public function testAKnownEtagIsSentAsIfNoneMatch(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = self::headersOf($options);

            return new MockResponse('', ['http_code' => 304]);
        });

        $this->fetcher($client)->fetch('tree', 'https://example.test/data.json', '"abc123"');

        self::assertArrayHasKey('if-none-match', $seen);
        self::assertArrayNotHasKey('if-modified-since', $seen);
    }

    public function testAKnownDateIsSentAsIfModifiedSince(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = self::headersOf($options);

            return new MockResponse('', ['http_code' => 304]);
        });

        $this->fetcher($client)->fetch('tree', 'https://example.test/data.json', 'Mon, 07 Sep 2026 10:00:00 GMT');

        self::assertArrayHasKey('if-modified-since', $seen);
        self::assertArrayNotHasKey('if-none-match', $seen);
    }

    public function testAnUnchangedSourceIsNotDownloadedAgain(): void
    {
        file_put_contents($this->dir.'/tree.json', '{"cached":true}');

        $client = new MockHttpClient(new MockResponse('', ['http_code' => 304]));
        $result = $this->fetcher($client)->fetch('tree', 'https://example.test/data.json', '"abc123"');

        self::assertFalse($result->changed);
        self::assertSame('{"cached":true}', $result->body);
    }

    public function testAFailingUpstreamIsReportedRatherThanThrown(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        $result = $this->fetcher($client)->fetch('tree', 'https://example.test/data.json');

        self::assertFalse($result->ok);
        self::assertNotNull($result->error);
    }

    /**
     * @param array<mixed> $options
     *
     * @return array<string, list<string>>
     */
    private static function headersOf(array $options): array
    {
        $headers = $options['normalized_headers'] ?? [];
        self::assertIsArray($headers);

        /** @var array<string, list<string>> $headers */
        return $headers;
    }

    private function fetcher(MockHttpClient $client): SourceFetcher
    {
        return new SourceFetcher($client, $this->dir, 'poe-build-creation-helper/0.1 (contact: someone@example.test)');
    }
}
