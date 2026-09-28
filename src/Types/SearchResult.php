<?php

namespace Whilesmart\WebSearch\Types;

final class SearchResult
{
    /**
     * @param  list<string>  $providers
     */
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly string $snippet,
        public readonly int $rank,
        public readonly array $providers = [],
        public readonly ?float $score = null,
    ) {}

    /**
     * Strips the parts of a URL that vary without changing the destination, so
     * the same page returned by two providers collapses to one result.
     */
    public function fingerprint(): string
    {
        $parts = parse_url($this->url);

        if ($parts === false || ! isset($parts['host'])) {
            return mb_strtolower(trim($this->url));
        }

        $host = preg_replace('/^www\./', '', mb_strtolower($parts['host']));
        $path = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $query = $this->normalizedQuery($parts['query'] ?? '');

        return $host.$port.$path.($query === '' ? '' : '?'.$query);
    }

    /**
     * @param  list<string>  $providers
     */
    public function withProviders(array $providers, ?float $score = null, ?int $rank = null): self
    {
        return new self(
            $this->title,
            $this->url,
            $this->snippet,
            $rank ?? $this->rank,
            $providers,
            $score ?? $this->score,
        );
    }

    private function normalizedQuery(string $query): string
    {
        $tracking = ['fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'];
        $parts = array_filter(explode('&', $query), function (string $part) use ($tracking): bool {
            $key = mb_strtolower(rawurldecode(explode('=', $part, 2)[0]));

            return ! str_starts_with($key, 'utm_') && ! in_array($key, $tracking, true);
        });

        sort($parts, SORT_STRING);

        return implode('&', $parts);
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['title'] ?? ''),
            (string) ($row['url'] ?? ''),
            (string) ($row['snippet'] ?? ''),
            (int) ($row['rank'] ?? 0),
            array_values((array) ($row['providers'] ?? [])),
            isset($row['score']) ? (float) $row['score'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'snippet' => $this->snippet,
            'rank' => $this->rank,
            'providers' => $this->providers,
            'score' => $this->score,
        ];
    }
}
