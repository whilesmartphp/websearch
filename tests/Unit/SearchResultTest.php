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

it('round-trips through an array', function () {
    $original = new SearchResult('Title', 'https://example.com', 'Snippet', 3, ['brave'], 0.5);

    expect(SearchResult::fromArray($original->toArray())->toArray())->toBe($original->toArray());
});
