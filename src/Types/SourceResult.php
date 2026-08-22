<?php

namespace Whilesmart\WebSearch\Types;

final class SourceResult
{
    /**
     * @param  list<Record>  $results
     */
    public function __construct(
        public readonly array $results,
        public readonly ?int $total = null,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'results' => array_map(fn (Record $r): array => $r->toArray(), $this->results),
        ];
    }
}
