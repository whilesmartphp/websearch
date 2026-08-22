<?php

namespace Whilesmart\WebSearch\Types;

final class SourceResponse
{
    /**
     * @param  list<Record>  $results
     * @param  list<string>  $used
     * @param  array<string, string>  $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly array $used = [],
        public readonly array $failures = [],
    ) {}

    public function isBlocked(): bool
    {
        return $this->results === [] && $this->used === [] && $this->failures !== [];
    }

    public function toArray(): array
    {
        return [
            'results' => array_map(fn (Record $r): array => $r->toArray(), $this->results),
            'used' => $this->used,
            'failures' => $this->failures,
        ];
    }
}
