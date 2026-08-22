<?php

namespace Whilesmart\WebSearch\Exceptions;

use RuntimeException;

class QuotaExhaustedException extends RuntimeException
{
    public function __construct(
        public readonly ?string $tenant,
        public readonly string $provider,
    ) {
        parent::__construct("Search quota exhausted for provider [{$provider}].");
    }
}
