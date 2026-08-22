<?php

use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\UsageMeter;
use Whilesmart\WebSearch\Enums\RoutingMode;
use Whilesmart\WebSearch\Exceptions\QuotaExhaustedException;
use Whilesmart\WebSearch\SearchRouter;
use Whilesmart\WebSearch\Types\SearchQuery;

// Response shapes follow the published Tavily and Brave API references.
function tavilyBody(array $urls): array
{
    return ['results' => array_map(fn ($u) => [
        'title' => 'Tavily '.$u, 'url' => $u, 'content' => 'snippet', 'score' => 0.9,
    ], $urls)];
}

function braveBody(array $urls): array
{
    return ['web' => ['results' => array_map(fn ($u) => [
        'title' => 'Brave '.$u, 'url' => $u, 'description' => 'snippet',
    ], $urls)]];
}

beforeEach(function () {
    config()->set('websearch.providers', ['tavily', 'brave']);
});

it('unions results across providers and dedups the overlap', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response(tavilyBody(['https://a.test/x', 'https://b.test/y'])),
        'api.search.brave.com/*' => Http::response(braveBody(['https://www.a.test/x/', 'https://c.test/z'])),
    ]);

    $response = app(SearchRouter::class)->search(new SearchQuery('boutiques', 10));

    expect($response->results)->toHaveCount(3)
        ->and($response->used)->toBe(['tavily', 'brave'])
        ->and($response->failures)->toBe([]);
});

it('ranks a result both providers returned above one only a single provider returned', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response(tavilyBody(['https://solo.test/1', 'https://shared.test/2'])),
        'api.search.brave.com/*' => Http::response(braveBody(['https://shared.test/2'])),
    ]);

    $response = app(SearchRouter::class)->search(new SearchQuery('boutiques', 10));

    expect($response->results[0]->url)->toContain('shared.test')
        ->and($response->results[0]->providers)->toBe(['tavily', 'brave']);
});

it('falls through to the next provider in waterfall mode', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response([], 429),
        'api.search.brave.com/*' => Http::response(braveBody(['https://c.test/z'])),
    ]);

    $response = app(SearchRouter::class)->search(new SearchQuery('boutiques', 10), RoutingMode::Waterfall);

    expect($response->used)->toBe(['brave'])
        ->and($response->failures)->toHaveKey('tavily')
        ->and($response->results)->toHaveCount(1);
});

it('reports a total blockade as blocked rather than as no matches', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response([], 429),
        'api.search.brave.com/*' => Http::response([], 503),
    ]);

    $response = app(SearchRouter::class)->search(new SearchQuery('boutiques', 10));

    expect($response->results)->toBe([])
        ->and($response->isBlocked())->toBeTrue()
        ->and($response->failures)->toHaveCount(2);
});

it('distinguishes an empty index from a blockade', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response(tavilyBody([])),
        'api.search.brave.com/*' => Http::response(braveBody([])),
    ]);

    $response = app(SearchRouter::class)->search(new SearchQuery('nothing here', 10));

    expect($response->results)->toBe([])
        ->and($response->isBlocked())->toBeFalse();
});

it('serves a repeated query from cache without calling the provider again', function () {
    Http::fake([
        'api.tavily.com/*' => Http::response(tavilyBody(['https://a.test/x'])),
        'api.search.brave.com/*' => Http::response(braveBody(['https://a.test/x'])),
    ]);

    $router = app(SearchRouter::class);
    $router->search(new SearchQuery('repeat me', 10));
    $router->search(new SearchQuery('repeat me', 10));

    Http::assertSentCount(2);
});

it('stops calling a provider whose tenant quota is spent', function () {
    Http::fake(['*' => Http::response(tavilyBody(['https://a.test/x']))]);

    app()->bind(UsageMeter::class, fn () => new class implements UsageMeter
    {
        public function consume(?string $tenant, string $provider, int $units = 1): void
        {
            throw new QuotaExhaustedException($tenant, $provider);
        }

        public function remaining(?string $tenant, string $provider): ?int
        {
            return 0;
        }
    });

    $response = app(SearchRouter::class)->search(new SearchQuery('boutiques', 10, tenant: 'ws_1'));

    expect($response->isBlocked())->toBeTrue()
        ->and($response->failures['tavily'])->toContain('quota exhausted');

    Http::assertNothingSent();
});
