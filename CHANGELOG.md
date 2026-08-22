# Changelog

## Unreleased

- Search routing across Brave, Tavily, and SearXNG with `merge` and `waterfall` modes.
- URL fingerprinting so the same page from two providers collapses to one result.
- Cross-provider agreement folded into result ranking.
- Per-tenant usage metering behind a `UsageMeter` contract, with a null default.
- Cached repeat queries, keyed on the normalized query.
- Page fetching behind a `ContentFetcher` contract, with a Crawl4AI driver.
- Structured feeds behind a `Source` contract, with a ReliefWeb driver.
