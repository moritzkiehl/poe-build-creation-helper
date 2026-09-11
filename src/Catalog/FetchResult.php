<?php

declare(strict_types=1);

namespace App\Catalog;

final readonly class FetchResult
{
    public function __construct(
        public bool $ok,
        public bool $changed,
        public string $body = '',
        public ?string $revision = null,
        public ?string $error = null,
    ) {
    }

    public static function failed(string $error): self
    {
        return new self(ok: false, changed: false, error: $error);
    }
}
