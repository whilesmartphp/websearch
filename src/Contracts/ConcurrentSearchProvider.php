<?php

namespace Whilesmart\WebSearch\Contracts;

use GuzzleHttp\Promise\PromiseInterface;
use Whilesmart\WebSearch\Types\SearchQuery;

interface ConcurrentSearchProvider extends SearchProvider
{
    public function searchAsync(SearchQuery $query): PromiseInterface;
}
