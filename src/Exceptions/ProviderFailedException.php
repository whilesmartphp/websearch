<?php

namespace Whilesmart\WebSearch\Exceptions;

use RuntimeException;
use Throwable;

class ProviderFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Provider [{$provider}] failed: {$reason}", 0, $previous);
    }
}
