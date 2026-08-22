<?php

namespace Whilesmart\WebSearch\Abstracts;

use Whilesmart\WebSearch\Contracts\Source;

abstract class ConfiguredSource implements Source
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected readonly string $instance,
        protected readonly array $config = [],
        protected readonly int $timeout = 15,
    ) {}

    public function name(): string
    {
        return $this->instance;
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return array_values((array) ($this->config['provides'] ?? $this->defaultCapabilities()));
    }

    /**
     * @return list<string>
     */
    abstract protected function defaultCapabilities(): array;

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
