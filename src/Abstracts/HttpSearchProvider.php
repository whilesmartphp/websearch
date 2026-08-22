<?php

namespace Whilesmart\WebSearch\Abstracts;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\SearchProvider;

abstract class HttpSearchProvider implements SearchProvider
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly array $config,
        protected readonly int $timeout = 15,
    ) {}

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)->acceptJson();
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
