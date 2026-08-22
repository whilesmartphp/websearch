<?php

namespace Whilesmart\WebSearch;

use Throwable;
use Whilesmart\WebSearch\Contracts\Source;
use Whilesmart\WebSearch\Types\Record;
use Whilesmart\WebSearch\Types\SourceQuery;
use Whilesmart\WebSearch\Types\SourceResponse;

class SourceRegistry
{
    /**
     * @param  array<string, Source>  $sources
     */
    public function __construct(private readonly array $sources = []) {}

    /**
     * @return array<string, Source>
     */
    public function all(): array
    {
        return array_filter($this->sources, fn (Source $s): bool => $s->isConfigured());
    }

    public function get(string $name): ?Source
    {
        $source = $this->sources[$name] ?? null;

        return $source?->isConfigured() === true ? $source : null;
    }

    /**
     * @return array<string, Source>
     */
    public function providing(string $capability): array
    {
        return array_filter(
            $this->all(),
            fn (Source $s): bool => in_array($capability, $s->provides(), true),
        );
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $tags = [];

        foreach ($this->all() as $source) {
            $tags = array_merge($tags, $source->provides());
        }

        return array_values(array_unique($tags));
    }

    /**
     * Queries every configured source answering for the capability. One source
     * failing takes that source out of the round; it never fails the call, and
     * it is always named in the response.
     */
    public function search(string $capability, SourceQuery $query): SourceResponse
    {
        $records = [];
        $used = [];
        $failures = [];

        foreach ($this->providing($capability) as $name => $source) {
            try {
                $result = $source->query($query);
            } catch (Throwable $e) {
                $failures[$name] = $e->getMessage();

                continue;
            }

            $used[] = $name;
            $records = array_merge($records, $result->results);
        }

        usort($records, fn (Record $a, Record $b): int => ($b->publishedAt?->getTimestamp() ?? 0) <=> ($a->publishedAt?->getTimestamp() ?? 0));

        return new SourceResponse(array_slice($records, 0, $query->limit), $used, $failures);
    }
}
