<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Feed
{
    public function __construct(
        private HttpClientInterface $client,
        private string $url,
        private array $cache = [],
        public int $hits = 0,
    ) {
    }

    // not a controller: not a map-request candidate
    public function read(Request $request): array
    {
        return json_decode($request->getContent(), true);
    }

    public function remember(string $key, mixed $value): void
    {
        $this->cache[$key] = $value;
        ++$this->hits;
    }
}
