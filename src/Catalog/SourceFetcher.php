<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Downloads a catalog source into the snapshot directory.
 *
 * Three rules from the design document are implemented here, and all three are
 * about being a good guest upstream (measure 7): identify ourselves with a
 * contact address, ask conditionally so an unchanged file is not downloaded
 * twice, and never let a broken upstream throw — a failed fetch is reported so
 * the sync can record it and leave the existing catalog alone.
 *
 * Snapshots land in an ignored directory and are never committed or served on:
 * the data belongs to Grinding Gear Games (measure 5).
 */
final class SourceFetcher
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $snapshotDir,
        private readonly string $userAgent,
    ) {
    }

    public function fetch(string $source, string $url, ?string $knownRevision = null): FetchResult
    {
        $headers = ['User-Agent' => $this->userAgent];
        if (null !== $knownRevision) {
            // An ETag is quoted per RFC 9110; a Last-Modified value never is.
            // Sending the wrong one of these is worse than sending neither: the
            // server ignores it and we re-download the whole file.
            $headers[str_starts_with($knownRevision, '"') || str_starts_with($knownRevision, 'W/') ? 'If-None-Match' : 'If-Modified-Since'] = $knownRevision;
        }

        try {
            $response = $this->http->request('GET', $url, ['headers' => $headers]);
            $status = $response->getStatusCode();

            if (304 === $status) {
                return new FetchResult(ok: true, changed: false, body: $this->cached($source), revision: $knownRevision);
            }

            if (200 !== $status) {
                return FetchResult::failed(\sprintf('%s answered %d.', $url, $status));
            }

            $body = $response->getContent();
            $this->store($source, $body);
            $headers = $response->getHeaders(false);

            return new FetchResult(
                ok: true,
                changed: true,
                body: $body,
                // ETag first: raw.githubusercontent.com sends one and no
                // Last-Modified, so a date-only fetcher never revalidates.
                revision: $headers['etag'][0] ?? $headers['last-modified'][0] ?? null,
            );
        } catch (\Throwable $e) {
            return FetchResult::failed($e->getMessage());
        }
    }

    public function path(string $source): string
    {
        return $this->snapshotDir.'/'.$source.'.json';
    }

    private function cached(string $source): string
    {
        $body = @file_get_contents($this->path($source));

        return false === $body ? '' : $body;
    }

    private function store(string $source, string $body): void
    {
        if (!is_dir($this->snapshotDir)) {
            mkdir($this->snapshotDir, 0o775, true);
        }

        file_put_contents($this->path($source), $body);
    }
}
