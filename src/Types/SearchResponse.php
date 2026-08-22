<?php

namespace Whilesmart\WebSearch\Types;

final class SearchResponse
{
    /**
     * @param  list<SearchResult>  $results
     * @param  list<string>  $used
     * @param  array<string, string>  $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly array $used = [],
        public readonly array $failures = [],
    ) {}

    /**
     * True when nothing came back because every provider errored, as opposed
     * to the query genuinely having no matches.
     */
    public function isBlocked(): bool
    {
        return $this->results === [] && $this->failures !== [];
    }

    public function toArray(): array
    {
        return [
            'results' => array_map(fn (SearchResult $r): array => $r->toArray(), $this->results),
            'used' => $this->used,
            'failures' => $this->failures,
        ];
    }
}
