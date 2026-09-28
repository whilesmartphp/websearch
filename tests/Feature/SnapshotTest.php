<?php

use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Enums\SnapshotFailure;
use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\SnapshotManager;

function snapshotFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../Fixtures/snapshot/'.$name);
}

/**
 * @return array{0: int, 1: array<string, string>}
 */
function capturedHeaders(string $name): array
{
    // curl writes one block per response, including 103 Early Hints; the last is the answer.
    $blocks = preg_split('/\r?\n\r?\n/', trim(snapshotFixture($name)));
    $lines = preg_split('/\r?\n/', trim(end($blocks)));
    preg_match('/\s(\d{3})/', array_shift($lines), $status);
    $headers = [];

    foreach ($lines as $line) {
        [$key, $value] = array_map('trim', explode(':', $line, 2));
        $headers[$key] = $value;
    }

    return [(int) $status[1], $headers];
}

function snapshotFailure(callable $call): SnapshotFailedException
{
    try {
        $call();
    } catch (SnapshotFailedException $e) {
        return $e;
    }

    throw new RuntimeException('Expected the snapshot to fail.');
}

it('snapshots a rendered page with its meta, open graph, media, favicon and screenshot', function () {
    Http::fake(['crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-sourceant.ndjson'))]);

    $snapshot = app(SnapshotManager::class)->snapshot('https://sourceant.ai/');

    expect($snapshot->fetcher)->toBe('crawl4ai')
        ->and($snapshot->statusCode)->toBe(200)
        ->and($snapshot->title)->toBe('Software intelligence for you and your coding agents. · SourceAnt')
        ->and($snapshot->description)->toStartWith('SourceAnt connects your code')
        ->and($snapshot->meta['author'])->toBe('SourceAnt')
        ->and($snapshot->openGraph['og:site_name'])->toBe('SourceAnt')
        ->and($snapshot->image())->toBe('https://sourceant.ai/brand/og.png')
        ->and($snapshot->favicon)->toBe('https://sourceant.ai/favicon.svg')
        ->and($snapshot->images[0]['src'])->toStartWith('https://sourceant.ai/_astro/')
        ->and($snapshot->screenshot)->not->toBeEmpty()
        ->and($snapshot->markdown)->not->toBeEmpty();

    Http::assertSent(fn ($request) => $request['urls'] === ['https://sourceant.ai/']
        && $request['crawler_config']['screenshot'] === true
        && $request['crawler_config']['cache_mode'] === 'bypass');
});

it('names the anti-bot vendor when the browser is blocked', function () {
    config()->set('websearch.snapshotters', ['crawl4ai']);
    Http::fake(['crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-g2-datadome.ndjson'))]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://www.g2.com/'));

    expect($e->failure)->toBe(SnapshotFailure::Blocked)
        ->and($e->vendor)->toBe('DataDome captcha')
        ->and($e->statusCode)->toBe(403);
});

it('reports a page that never finished loading as a timeout without the crawler source excerpt', function () {
    config()->set('websearch.snapshotters', ['crawl4ai']);
    Http::fake(['crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-zillow-timeout.ndjson'))]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://www.zillow.com/'));

    expect($e->failure)->toBe(SnapshotFailure::Timeout)
        ->and($e->detail)->toContain('Timeout 45000ms exceeded')
        ->and($e->detail)->not->toContain('Code context');
});

it('stops at a host the crawler refuses instead of retrying it', function () {
    Http::fake([
        'crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-ssrf-refused.json'), 400),
        'nonexistent-subdomain-xyz.sourceant.ai/*' => Http::response('should not be called'),
    ]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://nonexistent-subdomain-xyz.sourceant.ai/'));

    expect($e->failure)->toBe(SnapshotFailure::Refused)
        ->and($e->statusCode)->toBe(400);
    Http::assertSentCount(1);
});

it('reports a rejected crawler token as the crawler being unavailable', function () {
    config()->set('websearch.snapshotters', ['crawl4ai']);
    Http::fake(['crawl4ai.test:11235/crawl/stream' => Http::response(['detail' => 'Not authenticated'], 401)]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://sourceant.ai/'));

    expect($e->failure)->toBe(SnapshotFailure::Unavailable);
});

it('falls back to a plain request when the browser is blocked', function () {
    Http::fake([
        'crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-g2-datadome.ndjson')),
        'sourceant.ai/*' => Http::response(snapshotFixture('http-sourceant.html'), 200, ['Content-Type' => 'text/html']),
    ]);

    $snapshot = app(SnapshotManager::class)->snapshot('https://sourceant.ai/');

    expect($snapshot->fetcher)->toBe('http')
        ->and($snapshot->screenshot)->toBeNull()
        ->and($snapshot->title)->toContain('SourceAnt')
        ->and($snapshot->openGraph['og:image'])->toBe('https://sourceant.ai/brand/og.png')
        ->and($snapshot->favicon)->toBe('https://sourceant.ai/favicon.svg')
        ->and($snapshot->images)->not->toBeEmpty();
});

it('keeps every driver\'s failure when all of them are blocked', function () {
    [$status, $headers] = capturedHeaders('http-g2-datadome.headers');
    Http::fake([
        'crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-g2-datadome.ndjson')),
        'www.g2.com/*' => Http::response(snapshotFixture('http-g2-datadome.html'), $status, $headers),
    ]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://www.g2.com/'));

    expect($e->failure)->toBe(SnapshotFailure::Blocked)
        ->and(array_keys($e->attempts))->toBe(['crawl4ai', 'http'])
        ->and($e->attempts['http']->vendor)->toBe('DataDome')
        ->and($e->toArray()['attempts'])->toHaveCount(2);
});

it('recognises a PerimeterX block on a plain request', function () {
    [$status, $headers] = capturedHeaders('http-zillow-perimeterx.headers');
    Http::fake(['www.zillow.com/*' => Http::response(snapshotFixture('http-zillow-perimeterx.html'), $status, $headers)]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://www.zillow.com/', 'http'));

    expect($e->failure)->toBe(SnapshotFailure::Blocked)
        ->and($e->vendor)->toBe('PerimeterX')
        ->and($e->statusCode)->toBe(403);
});

it('reports a plain error status without calling it a block', function () {
    Http::fake(['example.com/*' => Http::response('Not found', 404)]);

    $e = snapshotFailure(fn () => app(SnapshotManager::class)->snapshot('https://example.com/missing', 'http'));

    expect($e->failure)->toBe(SnapshotFailure::HttpStatus)
        ->and($e->vendor)->toBeNull();
});

it('compares the drivers from the command line', function () {
    [$status, $headers] = capturedHeaders('http-g2-datadome.headers');
    Http::fake([
        'crawl4ai.test:11235/crawl/stream' => Http::response(snapshotFixture('crawl4ai-stream-g2-datadome.ndjson')),
        'www.g2.com/*' => Http::response(snapshotFixture('http-g2-datadome.html'), $status, $headers),
    ]);

    $this->artisan('websearch:snapshot', ['url' => ['https://www.g2.com/']])
        ->expectsOutputToContain('Every driver was blocked')
        ->assertSuccessful();
});
