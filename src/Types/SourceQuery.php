<?php

namespace Whilesmart\WebSearch\Types;

final class SourceQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly ?string $query = null,
        public readonly array $filters = [],
        public readonly int $limit = 20,
        public readonly int $offset = 0,
        public readonly ?string $tenant = null,
    ) {}
}
