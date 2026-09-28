<?php

namespace Whilesmart\WebSearch\Fetchers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\SnapshotFetcher;
use Whilesmart\WebSearch\Enums\SnapshotFailure;
use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\Support\HtmlHead;
use Whilesmart\WebSearch\Types\SiteSnapshot;

class HttpSnapshotFetcher implements SnapshotFetcher
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly int $timeout = 20,
    ) {}

    public function name(): string
    {
        return 'http';
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function snapshot(string $url): SiteSnapshot
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withUserAgent((string) ($this->config['user_agent'] ?? self::USER_AGENT))
                ->withHeaders(['Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'en'])
                ->get($url);
        } catch (ConnectionException $e) {
            $failure = str_contains($e->getMessage(), 'timed out') ? SnapshotFailure::Timeout : SnapshotFailure::Unreachable;

            throw new SnapshotFailedException($this->name(), $failure, $e->getMessage(), previous: $e);
        }

        $vendor = $this->antiBotVendor($response);

        if ($vendor !== null) {
            throw new SnapshotFailedException($this->name(), SnapshotFailure::Blocked, "{$vendor} answered {$response->status()}", $response->status(), $vendor);
        }

        if (! $response->successful()) {
            throw new SnapshotFailedException($this->name(), SnapshotFailure::HttpStatus, 'the site answered '.$response->status(), $response->status());
        }

        $html = $response->body();
        $finalUrl = (string) ($response->effectiveUri() ?? $url);
        $head = new HtmlHead($html, $finalUrl);
        [$meta, $social] = $head->meta();

        return new SiteSnapshot(
            url: $url,
            finalUrl: $finalUrl,
            statusCode: $response->status(),
            title: $head->title() ?: ($social['og:title'] ?? ''),
            description: $meta['description'] ?? $social['og:description'] ?? '',
            html: $html,
            markdown: '',
            meta: $meta,
            openGraph: $social,
            images: $head->images(),
            videos: $head->videos(),
            favicon: $head->favicon(),
            screenshot: null,
            fetcher: $this->name(),
        );
    }

    private function antiBotVendor(Response $response): ?string
    {
        return match (true) {
            $response->header('x-datadome') !== '' && $response->status() >= 400 => 'DataDome',
            $response->header('x-px-blocked') !== '' => 'PerimeterX',
            $response->header('cf-mitigated') === 'challenge' => 'Cloudflare',
            default => null,
        };
    }
}
