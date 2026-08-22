<?php

namespace Whilesmart\WebSearch\Metering;

use Whilesmart\WebSearch\Contracts\UsageMeter;

class NullUsageMeter implements UsageMeter
{
    public function consume(?string $tenant, string $provider, int $units = 1): void {}

    public function remaining(?string $tenant, string $provider): ?int
    {
        return null;
    }
}
