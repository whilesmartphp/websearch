<?php

namespace Whilesmart\WebSearch\Abstracts;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\ConcurrentSearchProvider;

abstract class HttpSearchProvider implements ConcurrentSearchProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
        protected readonly int $timeout = 15,
    ) {}

    protected function http(bool $async = false): PendingRequest
    {
        return Http::timeout($this->timeout)->acceptJson()->async($async);
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
