<?php

namespace Whilesmart\WebSearch\Fetchers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\SnapshotFetcher;
use Whilesmart\WebSearch\Enums\SnapshotFailure;
use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\Support\HtmlHead;
use Whilesmart\WebSearch\Support\Url;
use Whilesmart\WebSearch\Types\SiteSnapshot;

class Crawl4aiSnapshotFetcher implements SnapshotFetcher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'crawl4ai';
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->config['url'] ?? null);
    }

    public function snapshot(string $url): SiteSnapshot
    {
        $pageTimeout = (int) ($this->config['page_timeout'] ?? 45000);
        $token = $this->config['token'] ?? null;

        try {
            $response = Http::timeout(max($this->timeout, intdiv($pageTimeout, 1000) + 15))
                ->when($token, fn ($http) => $http->withToken((string) $token))
                // /crawl hides the failure reason behind a generic 500; the stream reports it per URL.
                ->post(rtrim((string) $this->config['url'], '/').'/crawl/stream', [
                    'urls' => [$url],
                    'crawler_config' => [
                        'screenshot' => (bool) ($this->config['screenshot'] ?? true),
                        'cache_mode' => 'bypass',
                        'wait_until' => $this->config['wait_until'] ?? 'networkidle',
                        'page_timeout' => $pageTimeout,
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new SnapshotFailedException($this->name(), SnapshotFailure::Unavailable, $e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw $this->serviceFailure($response);
        }

        $result = $this->result($response->body(), $url);

        if (! ($result['success'] ?? false)) {
            throw $this->crawlFailure($result);
        }

        $status = isset($result['status_code']) ? (int) $result['status_code'] : null;

        if ($status !== null && $status >= 400) {
            throw new SnapshotFailedException($this->name(), SnapshotFailure::HttpStatus, "the site answered {$status}", $status);
        }

        $html = (string) ($result['html'] ?? '');

        if (trim(strip_tags($html)) === '') {
            throw new SnapshotFailedException($this->name(), SnapshotFailure::Unknown, 'the page rendered empty', $status);
        }

        $finalUrl = (string) ($result['redirected_url'] ?? $url) ?: $url;
        $head = new HtmlHead($html, $finalUrl);
        [$meta, $social] = $this->splitMetadata((array) ($result['metadata'] ?? []), $finalUrl);
        [$headMeta, $headSocial] = $head->meta();

        return new SiteSnapshot(
            url: $url,
            finalUrl: $finalUrl,
            statusCode: $status,
            title: (string) ($result['metadata']['title'] ?? '') ?: $head->title(),
            description: (string) ($result['metadata']['description'] ?? '') ?: ($headMeta['description'] ?? ''),
            html: $html,
            markdown: (string) ($result['markdown']['raw_markdown'] ?? (is_string($result['markdown'] ?? null) ? $result['markdown'] : '')),
            meta: $meta + $headMeta,
            openGraph: $social + $headSocial,
            images: $this->images((array) ($result['media']['images'] ?? []), $finalUrl),
            videos: $this->videos((array) ($result['media']['videos'] ?? []), $finalUrl),
            favicon: $head->favicon(),
            screenshot: $result['screenshot'] ?? null,
            fetcher: $this->name(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $body, string $url): array
    {
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded) && isset($decoded['url'])) {
                return $decoded;
            }
        }

        throw new SnapshotFailedException($this->name(), SnapshotFailure::Unknown, "no result for {$url} in the crawl stream");
    }

    private function serviceFailure(Response $response): SnapshotFailedException
    {
        $detail = (string) ($response->json('detail') ?? $response->json('error') ?? 'HTTP '.$response->status());

        $failure = match (true) {
            str_contains($detail, 'SSRF') => SnapshotFailure::Refused,
            in_array($response->status(), [401, 403], true) => SnapshotFailure::Unavailable,
            $response->status() >= 500 => SnapshotFailure::Unavailable,
            default => SnapshotFailure::Unknown,
        };

        return new SnapshotFailedException($this->name(), $failure, $detail, $response->status());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function crawlFailure(array $result): SnapshotFailedException
    {
        $message = (string) ($result['error_message'] ?? '');
        $status = isset($result['status_code']) ? (int) $result['status_code'] : null;

        if (preg_match('/Blocked by anti-bot protection:\s*(.+)/', $message, $match)) {
            return new SnapshotFailedException($this->name(), SnapshotFailure::Blocked, $message, $status, trim($match[1]));
        }

        if (preg_match('/Timeout \d+ms exceeded\.?/', $message, $match)) {
            return new SnapshotFailedException($this->name(), SnapshotFailure::Timeout, 'page load: '.$match[0], $status);
        }

        $failure = match (true) {
            (bool) preg_match('/net::ERR_(NAME_NOT_RESOLVED|CONNECTION_REFUSED|CONNECTION_RESET|ADDRESS_UNREACHABLE|CONNECTION_TIMED_OUT)/', $message) => SnapshotFailure::Unreachable,
            $status !== null && $status >= 400 => SnapshotFailure::HttpStatus,
            default => SnapshotFailure::Unknown,
        };

        return new SnapshotFailedException($this->name(), $failure, $this->firstLines($message), $status);
    }

    private function firstLines(string $message): string
    {
        $cut = strpos($message, "\nCode context:");

        return trim($cut === false ? $message : substr($message, 0, $cut));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function splitMetadata(array $metadata, string $base): array
    {
        $meta = [];
        $social = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($value) || $value === '' || $key === 'title') {
                continue;
            }

            if (str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:')) {
                $social[$key] = str_ends_with($key, ':image') || $key === 'og:url' ? Url::resolve($base, $value) : $value;
            } else {
                $meta[$key] = $value;
            }
        }

        return [$meta, $social];
    }

    /**
     * @param  array<int, mixed>  $images
     * @return list<array{src: string, alt: string}>
     */
    private function images(array $images, string $base): array
    {
        $seen = [];

        foreach ($images as $image) {
            $src = is_array($image) ? trim((string) ($image['src'] ?? '')) : '';

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $src = Url::resolve($base, $src);
            $seen[$src] ??= ['src' => $src, 'alt' => trim((string) ($image['alt'] ?? ''))];
        }

        return array_values($seen);
    }

    /**
     * @param  array<int, mixed>  $videos
     * @return list<string>
     */
    private function videos(array $videos, string $base): array
    {
        $urls = [];

        foreach ($videos as $video) {
            $src = is_array($video) ? trim((string) ($video['src'] ?? '')) : '';

            if ($src !== '') {
                $urls[] = Url::resolve($base, $src);
            }
        }

        return array_values(array_unique($urls));
    }
}
