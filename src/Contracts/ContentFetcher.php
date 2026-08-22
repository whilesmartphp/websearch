<?php

namespace Whilesmart\WebSearch\Contracts;

use Whilesmart\WebSearch\Types\FetchedPage;

interface ContentFetcher
{
    public function name(): string;

    public function isConfigured(): bool;

    public function fetch(string $url): FetchedPage;
}
