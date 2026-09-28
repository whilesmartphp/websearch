<?php

namespace Whilesmart\WebSearch\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Whilesmart\WebSearch\WebSearchServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WebSearchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('websearch.providers', ['tavily', 'serper', 'brave', 'searxng']);
        $app['config']->set('websearch.tavily.api_key', 'tavily-test-key');
        $app['config']->set('websearch.brave.api_key', 'brave-test-key');
        $app['config']->set('websearch.serper.api_key', 'serper-test-key');
        $app['config']->set('websearch.searxng.url', 'http://searxng.test:8080');
        $app['config']->set('websearch.crawl4ai.url', 'http://crawl4ai.test:11235');
        $app['config']->set('websearch.sources', [
            'opportunity_desk' => [
                'driver' => 'feed',
                'url' => 'https://opportunitydesk.test/feed/',
                'provides' => ['funding', 'scholarships'],
            ],
            'reliefweb' => [
                'driver' => 'reliefweb',
                'url' => 'https://api.reliefweb.int/v2',
                'appname' => 'websearch-test',
                'provides' => ['jobs'],
            ],
        ]);
        $app['config']->set('cache.default', 'array');
    }
}
