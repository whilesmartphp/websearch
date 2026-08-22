<?php

return [

    // 'merge' queries every configured provider and unions the results, which
    // costs one call per provider but ranks by cross-provider agreement.
    // 'waterfall' stops at the first provider that answers.
    'mode' => env('WEBSEARCH_MODE', 'merge'),

    'timeout' => (int) env('WEBSEARCH_TIMEOUT', 15),

    // Ordered. Waterfall walks this list; merge queries all of them.
    'providers' => ['tavily', 'brave', 'searxng'],

    'cache' => [
        'enabled' => (bool) env('WEBSEARCH_CACHE', true),
        'ttl' => (int) env('WEBSEARCH_CACHE_TTL', 86400),
        'store' => env('WEBSEARCH_CACHE_STORE'),
    ],

    'brave' => [
        'api_key' => env('BRAVE_SEARCH_API_KEY'),
        'endpoint' => 'https://api.search.brave.com/res/v1/web/search',
    ],

    'tavily' => [
        'api_key' => env('TAVILY_API_KEY'),
        'endpoint' => 'https://api.tavily.com/search',
        'search_depth' => env('TAVILY_SEARCH_DEPTH', 'basic'),
    ],

    'searxng' => [
        'url' => env('WEBSEARCH_SEARXNG_URL'),
    ],

    'fetchers' => ['crawl4ai'],

    'crawl4ai' => [
        'url' => env('WEBSEARCH_CRAWL4AI_URL'),
        'token' => env('WEBSEARCH_CRAWL4AI_TOKEN'),
        'timeout' => (int) env('WEBSEARCH_CRAWL_TIMEOUT', 30),
    ],

    // Keyed by instance name. 'driver' picks the implementation, so one
    // driver backs many instances: every opportunity site that publishes a
    // feed is another 'feed' entry rather than another class.
    //
    // 'provides' is how callers select. Sources are not interchangeable the
    // way search providers are, so a query fans out across one capability,
    // never across everything configured.
    'sources' => [

        'opportunity_desk' => [
            'driver' => 'feed',
            'url' => 'https://opportunitydesk.org/feed/',
            'provides' => ['funding', 'scholarships'],
        ],

        'reliefweb' => [
            'driver' => 'reliefweb',
            'url' => 'https://api.reliefweb.int/v2',
            'appname' => env('RELIEFWEB_APPNAME'),
            'provides' => ['jobs'],
        ],

    ],

];
