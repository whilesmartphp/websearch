<?php

namespace Whilesmart\WebSearch;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;
use Whilesmart\WebSearch\Console\ProbeCommand;
use Whilesmart\WebSearch\Contracts\ContentFetcher;
use Whilesmart\WebSearch\Contracts\SearchProvider;
use Whilesmart\WebSearch\Contracts\Source;
use Whilesmart\WebSearch\Contracts\UsageMeter;
use Whilesmart\WebSearch\Fetchers\Crawl4aiFetcher;
use Whilesmart\WebSearch\Metering\NullUsageMeter;
use Whilesmart\WebSearch\Providers\BraveProvider;
use Whilesmart\WebSearch\Providers\SearxngProvider;
use Whilesmart\WebSearch\Providers\TavilyProvider;
use Whilesmart\WebSearch\Sources\FeedSource;
use Whilesmart\WebSearch\Sources\ReliefWebSource;

class WebSearchServiceProvider extends ServiceProvider
{
    /**
     * Drivers shipped with the package. A host adds its own by pushing onto
     * the matching registry before the router resolves.
     *
     * @var array<string, class-string>
     */
    public static array $searchProviders = [
        'brave' => BraveProvider::class,
        'tavily' => TavilyProvider::class,
        'searxng' => SearxngProvider::class,
    ];

    /** @var array<string, class-string> */
    public static array $fetchers = [
        'crawl4ai' => Crawl4aiFetcher::class,
    ];

    /**
     * Source drivers, keyed by driver name. A config entry names the driver it
     * wants, so one driver can back many configured instances.
     *
     * @var array<string, class-string>
     */
    public static array $sources = [
        'reliefweb' => ReliefWebSource::class,
        'feed' => FeedSource::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/websearch.php', 'websearch');

        $this->app->bindIf(UsageMeter::class, NullUsageMeter::class);

        $this->app->singleton(SearchRouter::class, function ($app): SearchRouter {
            $config = $app['config']->get('websearch');

            return new SearchRouter(
                $this->buildSearchProviders($config),
                $app->make(UsageMeter::class),
                $app->make(CacheFactory::class)->store($config['cache']['store'] ?? null),
                $config,
            );
        });

        $this->app->singleton('websearch.fetchers', fn ($app): array => $this->buildFetchers($app['config']->get('websearch')));
        $this->app->singleton(SourceRegistry::class, fn ($app): SourceRegistry => new SourceRegistry(
            $this->buildSources($app['config']->get('websearch')),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ProbeCommand::class]);

            $this->publishes([
                __DIR__.'/../config/websearch.php' => config_path('websearch.php'),
            ], 'websearch-config');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<SearchProvider>
     */
    private function buildSearchProviders(array $config): array
    {
        $built = [];

        foreach ($config['providers'] ?? [] as $name) {
            $class = self::$searchProviders[$name] ?? null;

            if ($class !== null) {
                $built[] = new $class($config[$name] ?? [], (int) ($config['timeout'] ?? 15));
            }
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, ContentFetcher>
     */
    private function buildFetchers(array $config): array
    {
        $built = [];

        foreach ($config['fetchers'] ?? [] as $name) {
            $class = self::$fetchers[$name] ?? null;

            if ($class !== null) {
                $built[$name] = new $class($config[$name] ?? [], (int) ($config[$name]['timeout'] ?? 30));
            }
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, Source>
     */
    private function buildSources(array $config): array
    {
        $built = [];

        foreach ($config['sources'] ?? [] as $name => $spec) {
            $class = self::$sources[$spec['driver'] ?? $name] ?? null;

            if ($class !== null) {
                $built[$name] = new $class($name, $spec, (int) ($spec['timeout'] ?? $config['timeout'] ?? 15));
            }
        }

        return $built;
    }
}
