<?php

use Illuminate\Support\Facades\Http;
use Whilesmart\WebSearch\Contracts\ContentFetcher;
use Whilesmart\WebSearch\SourceRegistry;
use Whilesmart\WebSearch\Types\SourceQuery;

function rssBody(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Opportunity Desk</title>
    <item>
      <title>Pulitzer Center Global South Microgrants 2026</title>
      <link>https://opportunitydesk.test/2026/08/21/pulitzer/</link>
      <pubDate>Fri, 21 Aug 2026 09:46:17 +0000</pubDate>
      <description>&lt;p&gt;Up to $4,000&lt;/p&gt;</description>
      <category>Africa</category>
      <category>Grants</category>
    </item>
    <item>
      <title>NANS Innovate 2026</title>
      <link>https://opportunitydesk.test/2026/08/20/nans/</link>
      <pubDate>Thu, 20 Aug 2026 09:09:03 +0000</pubDate>
      <description>Contest</description>
      <category>Contests</category>
    </item>
  </channel>
</rss>
XML;
}

function reliefWebBody(): array
{
    return [
        'totalCount' => 1,
        'data' => [[
            'id' => '4210',
            'fields' => [
                'title' => 'Procurement Officer',
                'url' => 'https://reliefweb.int/job/4210',
                'date' => ['created' => '2026-08-01T00:00:00+00:00', 'closing' => '2026-09-01T00:00:00+00:00'],
                'source' => [['name' => 'UNDP']],
                'country' => [['name' => 'Cameroon']],
                'career_categories' => [['name' => 'Logistics']],
            ],
        ]],
    ];
}

it('reads an rss feed into generic records', function () {
    Http::fake(['opportunitydesk.test/*' => Http::response(rssBody(), 200, ['Content-Type' => 'application/rss+xml'])]);

    $result = app(SourceRegistry::class)->get('opportunity_desk')->query(new SourceQuery);

    expect($result->total)->toBe(2)
        ->and($result->results[0]->title)->toBe('Pulitzer Center Global South Microgrants 2026')
        ->and($result->results[0]->source)->toBe('opportunity_desk')
        ->and($result->results[0]->attributes['categories'])->toBe(['Africa', 'Grants'])
        ->and($result->results[0]->publishedAt?->format('Y-m-d'))->toBe('2026-08-21');
});

it('maps a reliefweb job onto the same record shape', function () {
    Http::fake(['api.reliefweb.int/*' => Http::response(reliefWebBody())]);

    $result = app(SourceRegistry::class)->get('reliefweb')->query(new SourceQuery('procurement'));

    expect($result->results[0]->attributes['organization'])->toBe('UNDP')
        ->and($result->results[0]->attributes['country'])->toBe(['Cameroon'])
        ->and($result->results[0]->expiresAt?->format('Y-m-d'))->toBe('2026-09-01');
});

it('fans out only across sources that answer for the capability', function () {
    Http::fake([
        'opportunitydesk.test/*' => Http::response(rssBody(), 200),
        'api.reliefweb.int/*' => Http::response(reliefWebBody()),
    ]);

    $funding = app(SourceRegistry::class)->search('funding', new SourceQuery);

    expect($funding->used)->toBe(['opportunity_desk']);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'reliefweb'));
});

it('keeps going when one source in the capability fails and names the failure', function () {
    config()->set('websearch.sources.broken', [
        'driver' => 'feed',
        'url' => 'https://broken.test/feed/',
        'provides' => ['funding'],
    ]);

    Http::fake([
        'opportunitydesk.test/*' => Http::response(rssBody(), 200),
        'broken.test/*' => Http::response('', 503),
    ]);

    $funding = app(SourceRegistry::class)->search('funding', new SourceQuery);

    expect($funding->used)->toBe(['opportunity_desk'])
        ->and($funding->failures)->toHaveKey('broken')
        ->and($funding->results)->not->toBeEmpty()
        ->and($funding->isBlocked())->toBeFalse();
});

it('reports a capability whose every source failed as blocked', function () {
    Http::fake(['opportunitydesk.test/*' => Http::response('', 503)]);

    $funding = app(SourceRegistry::class)->search('funding', new SourceQuery);

    expect($funding->isBlocked())->toBeTrue()
        ->and($funding->failures)->toHaveKey('opportunity_desk');
});

it('skips a source that has no credentials configured', function () {
    config()->set('websearch.sources.reliefweb.appname', null);

    expect(app(SourceRegistry::class)->get('reliefweb'))->toBeNull()
        ->and(array_keys(app(SourceRegistry::class)->all()))->toBe(['opportunity_desk']);
});

it('lists the capabilities its configured sources answer for', function () {
    expect(app(SourceRegistry::class)->capabilities())
        ->toContain('funding')
        ->toContain('scholarships')
        ->toContain('jobs');
});

it('turns a url into markdown through the fetcher', function () {
    Http::fake(['crawl4ai.test:11235/*' => Http::response(['markdown' => '# Hello', 'title' => 'Hello'])]);

    /** @var ContentFetcher $fetcher */
    $fetcher = app('websearch.fetchers')['crawl4ai'];
    $page = $fetcher->fetch('https://example.com');

    expect($page->markdown)->toBe('# Hello')
        ->and($page->fetcher)->toBe('crawl4ai');
});
