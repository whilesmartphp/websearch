<?php

namespace Whilesmart\WebSearch\Contracts;

use Whilesmart\WebSearch\Types\SourceQuery;
use Whilesmart\WebSearch\Types\SourceResult;

/**
 * A queryable feed of structured records. The core knows only the fields on
 * Record; everything a given domain adds lives in Record::$attributes and is
 * described by schema().
 */
interface Source
{
    public function name(): string;

    /**
     * Capability tags this source answers for, such as 'jobs', 'funding', or
     * 'institutions'. Callers fan out across a capability, never across every
     * configured source, because two sources are only interchangeable when
     * they answer the same kind of question.
     *
     * @return list<string>
     */
    public function provides(): array;

    public function isConfigured(): bool;

    /**
     * JSON Schema describing the shape of Record::$attributes for this source.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    public function query(SourceQuery $query): SourceResult;
}
