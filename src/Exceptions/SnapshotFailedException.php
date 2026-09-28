<?php

namespace Whilesmart\WebSearch\Exceptions;

use Throwable;
use Whilesmart\WebSearch\Enums\SnapshotFailure;

class SnapshotFailedException extends ProviderFailedException
{
    /**
     * @param  array<string, SnapshotFailedException>  $attempts
     */
    public function __construct(
        string $provider,
        public readonly SnapshotFailure $failure,
        public readonly string $detail,
        public readonly ?int $statusCode = null,
        public readonly ?string $vendor = null,
        public readonly array $attempts = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($provider, $failure->value.($vendor ? " ({$vendor})" : '').': '.$detail, $previous);
    }

    /**
     * @param  array<string, SnapshotFailedException>  $attempts
     */
    public static function fromAttempts(array $attempts): self
    {
        $last = end($attempts);

        return new self(
            implode(',', array_keys($attempts)),
            $last->failure,
            implode('; ', array_map(fn (self $e): string => $e->getMessage(), $attempts)),
            $last->statusCode,
            $last->vendor,
            $attempts,
        );
    }

    public function toArray(): array
    {
        return [
            'fetcher' => $this->provider,
            'failure' => $this->failure->value,
            'vendor' => $this->vendor,
            'status_code' => $this->statusCode,
            'detail' => $this->detail,
            'attempts' => array_map(fn (self $e): array => $e->toArray(), $this->attempts),
        ];
    }
}
