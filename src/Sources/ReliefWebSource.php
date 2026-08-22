<?php

namespace Whilesmart\WebSearch\Sources;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Abstracts\ConfiguredSource;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\Record;
use Whilesmart\WebSearch\Types\SourceQuery;
use Whilesmart\WebSearch\Types\SourceResult;

/**
 * ReliefWeb charges nothing and issues no key, but since November 2025 the
 * appname must be pre-approved, so an unregistered name is rejected rather
 * than merely logged.
 */
class ReliefWebSource extends ConfiguredSource
{
    public function isConfigured(): bool
    {
        return (bool) $this->option('appname');
    }

    protected function defaultCapabilities(): array
    {
        return ['jobs', 'reports'];
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'content_type' => ['type' => 'string'],
                'organization' => ['type' => 'string'],
                'country' => ['type' => 'array', 'items' => ['type' => 'string']],
                'career_category' => ['type' => 'string'],
            ],
        ];
    }

    public function query(SourceQuery $query): SourceResult
    {
        $type = (string) ($query->filters['content_type'] ?? 'jobs');

        $params = [
            'appname' => (string) $this->option('appname'),
            'limit' => min($query->limit, 1000),
            'offset' => $query->offset,
            'fields' => ['include' => ['title', 'url', 'date', 'source', 'country', 'career_categories']],
        ];

        if ($query->query !== null && $query->query !== '') {
            $params['query'] = ['value' => $query->query];
        }

        $response = Http::timeout($this->timeout)
            ->acceptJson()
            ->post(rtrim((string) $this->option('url', 'https://api.reliefweb.int/v2'), '/').'/'.$type, $params);

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $records = [];

        foreach (array_values($response->json('data') ?? []) as $row) {
            $fields = $row['fields'] ?? [];

            $records[] = new Record(
                (string) ($row['id'] ?? ''),
                (string) ($fields['title'] ?? ''),
                (string) ($fields['url'] ?? ''),
                $this->name(),
                $this->date($fields['date']['created'] ?? null),
                $this->date($fields['date']['closing'] ?? null),
                [
                    'content_type' => $type,
                    'organization' => $fields['source'][0]['name'] ?? null,
                    'country' => array_column($fields['country'] ?? [], 'name'),
                    'career_category' => $fields['career_categories'][0]['name'] ?? null,
                ],
            );
        }

        return new SourceResult($records, $response->json('totalCount'));
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $parsed === false ? null : $parsed;
    }
}
