<?php

namespace Whilesmart\WebSearch\Contracts;

use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\Types\SiteSnapshot;

interface SnapshotFetcher
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @throws SnapshotFailedException
     */
    public function snapshot(string $url): SiteSnapshot;
}
