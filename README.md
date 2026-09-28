# WebSearch

Provider-routed web search, page fetching, and structured feeds for Laravel applications.

Search engines block automated traffic from datacentre addresses, so a single
search backend that works in development fails in production. WebSearch puts an
ordered set of providers behind one interface, unions or falls through their
results, caches repeats, and meters usage per tenant.

## Install

```bash
composer require whilesmart/websearch
php artisan vendor:publish --tag=websearch-config
```

## Use

```php
use Whilesmart\WebSearch\SearchRouter;
use Whilesmart\WebSearch\Types\SearchQuery;

$response = app(SearchRouter::class)->search(
    new SearchQuery('fashion boutiques instagram', limit: 10, tenant: 'ws_1')
);

$response->results;        // list<SearchResult>, deduped and ranked
$response->used;           // which providers answered
$response->isBlocked();    // true when every provider errored
```

`isBlocked()` is the distinction that matters: an empty result list because the
query has no matches is not the same as an empty list because every provider
returned a challenge, and callers need to tell them apart.

### Routing modes

`merge` starts every configured HTTP provider concurrently, unions the results,
collapses equivalent URLs, and combines the provider rankings with reciprocal
rank fusion. One call per provider, with failures isolated by provider.

`waterfall` continues through failures and empty responses, then stops at the
first provider returning results.

Set the default in `config/websearch.php` or pass `RoutingMode` per call.

## Three interfaces

`SearchProvider` returns unstructured web results. Ships with Brave, Tavily,
Serper, and SearXNG. The built-in HTTP providers also implement
`ConcurrentSearchProvider` so merge mode can run them together.

`ContentFetcher` turns a URL into markdown. Ships with Crawl4AI.
Call `FetcherManager::fetch()` to use the first configured fetcher, or name a
specific driver.

`Source` is a queryable feed of structured records. Each source declares a JSON
Schema for its own `attributes`, so the core stays ignorant of any one domain:
tenders carry a funder and a value, jobs carry a salary and an employer, and
neither requires a change to `Record`. Ships with ReliefWeb and a generic feed
driver that reads any RSS 2.0 or Atom URL.

### Configuring sources

Sources are keyed by instance name, and `driver` picks the implementation, so
one driver backs many instances. Every opportunity site that publishes a feed
is another entry, not another class.

```php
'sources' => [
    'opportunity_desk' => [
        'driver' => 'feed',
        'url' => 'https://opportunitydesk.org/feed/',
        'provides' => ['funding', 'scholarships'],
    ],
    'adzuna' => [
        'driver' => 'adzuna',
        'app_id' => env('ADZUNA_APP_ID'),
        'provides' => ['jobs'],
    ],
],
```

A source with no credentials is skipped, not failed.

### Querying sources

Search providers are interchangeable, so the router fans out across all of
them. Sources are not: institutions, jobs, and funding calls are different
questions, and unioning them would be meaningless. So a source query fans out
across one capability.

```php
$registry = app(SourceRegistry::class);

$registry->search('funding', new SourceQuery(limit: 20));  // every funding source
$registry->get('reliefweb')?->query($query);              // one source by name
$registry->capabilities();                                // what is configured
```

Both entry points answer with the same shape: `results`, `used`, `failures`,
and `isBlocked()`. Only the record type differs. One source failing takes that
source out of the round and is named in `failures`. `isBlocked()` marks the case where every source for the capability
failed, which is not the same as the capability having nothing to report.

A feed carries an announcement, not a filled-in record. Deadlines, amounts and
eligibility live in the linked page, so feed-backed sources stay sparse until a
fetch-and-extract pass runs over the URL.

## Adding a driver

Implement the contract and register the class before the router resolves:

```php
WebSearchServiceProvider::$searchProviders['acme'] = AcmeProvider::class;
```

Then add `acme` to `websearch.providers` and give it a `websearch.acme` config block.

## Metering

Bind `UsageMeter` to reach your own entitlements. The default is a null meter
that counts nothing. A meter that throws `QuotaExhaustedException` takes that
provider out of the round without failing the whole search.

## Cache

Repeated queries are served from the configured cache store, keyed on the
normalized query, for 24 hours by default. Prospecting agents on a schedule
re-issue near-identical queries every run, so this is the largest cost lever in
the package.

## Testing

```bash
make up && make install && make test
```
