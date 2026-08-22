<?php

namespace Whilesmart\WebSearch\Sources;

use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Whilesmart\WebSearch\Abstracts\ConfiguredSource;
use Whilesmart\WebSearch\Exceptions\ProviderFailedException;
use Whilesmart\WebSearch\Types\Record;
use Whilesmart\WebSearch\Types\SourceQuery;
use Whilesmart\WebSearch\Types\SourceResult;

/**
 * Reads any RSS 2.0 or Atom feed. Opportunity and scholarship sites almost
 * never expose an API but almost always publish a feed, so one configured
 * instance of this driver per feed URL covers a whole category of source
 * without a class each.
 *
 * A feed carries an announcement, not a filled-in record: deadlines, amounts
 * and eligibility live in the linked page, so attributes stay sparse until a
 * fetch-and-extract pass runs over the URL.
 */
class FeedSource extends ConfiguredSource
{
    public function isConfigured(): bool
    {
        return (bool) $this->option('url');
    }

    protected function defaultCapabilities(): array
    {
        return ['announcements'];
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'categories' => ['type' => 'array', 'items' => ['type' => 'string']],
                'summary' => ['type' => 'string'],
                'feed' => ['type' => 'string'],
            ],
        ];
    }

    public function query(SourceQuery $query): SourceResult
    {
        $response = Http::timeout($this->timeout)->get((string) $this->option('url'));

        if (! $response->successful()) {
            throw new ProviderFailedException($this->name(), 'HTTP '.$response->status());
        }

        $xml = @simplexml_load_string($response->body());

        if ($xml === false) {
            throw new ProviderFailedException($this->name(), 'response is not a parseable feed');
        }

        $records = $xml->getName() === 'feed'
            ? $this->fromAtom($xml)
            : $this->fromRss($xml);

        if ($query->query !== null && $query->query !== '') {
            $needle = mb_strtolower($query->query);
            $records = array_values(array_filter(
                $records,
                fn (Record $r): bool => str_contains(mb_strtolower($r->title), $needle),
            ));
        }

        return new SourceResult(array_slice($records, $query->offset, $query->limit), count($records));
    }

    /**
     * @return list<Record>
     */
    private function fromRss(SimpleXMLElement $xml): array
    {
        $records = [];

        foreach ($xml->channel->item ?? [] as $item) {
            $link = (string) $item->link;

            $records[] = new Record(
                $link !== '' ? $link : (string) $item->guid,
                trim((string) $item->title),
                $link,
                $this->name(),
                $this->date((string) $item->pubDate),
                null,
                [
                    'categories' => array_map('strval', iterator_to_array($item->category ?? [], false)),
                    'summary' => trim(strip_tags((string) $item->description)),
                    'feed' => (string) $this->option('url'),
                ],
            );
        }

        return $records;
    }

    /**
     * @return list<Record>
     */
    private function fromAtom(SimpleXMLElement $xml): array
    {
        $records = [];

        foreach ($xml->entry ?? [] as $entry) {
            $link = (string) ($entry->link['href'] ?? '');

            $categories = [];

            foreach ($entry->category ?? [] as $category) {
                $categories[] = (string) ($category['term'] ?? '');
            }

            $records[] = new Record(
                $link !== '' ? $link : (string) $entry->id,
                trim((string) $entry->title),
                $link,
                $this->name(),
                $this->date((string) ($entry->published ?: $entry->updated)),
                null,
                [
                    'categories' => array_values(array_filter($categories)),
                    'summary' => trim(strip_tags((string) ($entry->summary ?: $entry->content))),
                    'feed' => (string) $this->option('url'),
                ],
            );
        }

        return $records;
    }

    private function date(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
