<?php

use Whilesmart\WebSearch\Types\SearchResult;

function result(string $url): SearchResult
{
    return new SearchResult('t', $url, 's', 1);
}

it('collapses urls that differ only in host prefix or trailing slash', function () {
    expect(result('https://www.example.com/a/')->fingerprint())
        ->toBe(result('https://example.com/a')->fingerprint());
});

it('keeps distinct paths apart', function () {
    expect(result('https://example.com/a')->fingerprint())
        ->not->toBe(result('https://example.com/b')->fingerprint());
});

it('keeps meaningful query values apart', function () {
    expect(result('https://example.com/search?q=alpha')->fingerprint())
        ->not->toBe(result('https://example.com/search?q=beta')->fingerprint());
});

it('collapses tracking parameters and query ordering', function () {
    expect(result('https://www.example.com/search?b=2&a=1&utm_source=email')->fingerprint())
        ->toBe(result('http://example.com/search?a=1&b=2')->fingerprint());
});

it('round-trips through an array', function () {
    $original = new SearchResult('Title', 'https://example.com', 'Snippet', 3, ['brave'], 0.5);

    expect(SearchResult::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});
