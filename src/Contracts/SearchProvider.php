<?php

namespace Whilesmart\WebSearch\Contracts;

use Whilesmart\WebSearch\Types\SearchQuery;
use Whilesmart\WebSearch\Types\SearchResult;

interface SearchProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @return list<SearchResult>
     */
    public function search(SearchQuery $query): array;
}
