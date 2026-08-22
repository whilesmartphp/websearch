<?php

namespace Whilesmart\WebSearch\Types;

final class SearchQuery
{
    public function __construct(
        public readonly string $query,
        public readonly int $limit = 8,
        public readonly ?string $country = null,
        public readonly ?string $language = null,
        public readonly ?string $tenant = null,
    ) {}

    public function cacheKey(string $provider): string
    {
        return 'websearch:'.$provider.':'.sha1(implode('|', [
            mb_strtolower(trim(preg_replace('/\s+/', ' ', $this->query))),
            $this->limit,
            $this->country ?? '',
            $this->language ?? '',
        ]));
    }
}
