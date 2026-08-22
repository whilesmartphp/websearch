<?php

namespace Whilesmart\WebSearch\Contracts;

use Whilesmart\WebSearch\Exceptions\QuotaExhaustedException;

interface UsageMeter
{
    /**
     * @throws QuotaExhaustedException
     */
    public function consume(?string $tenant, string $provider, int $units = 1): void;

    public function remaining(?string $tenant, string $provider): ?int;
}
