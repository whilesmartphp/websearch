<?php

namespace Whilesmart\WebSearch\Console;

use Illuminate\Console\Command;
use Whilesmart\WebSearch\Enums\SnapshotFailure;
use Whilesmart\WebSearch\Exceptions\SnapshotFailedException;
use Whilesmart\WebSearch\SnapshotManager;

class SnapshotCommand extends Command
{
    protected $signature = 'websearch:snapshot
        {url* : Pages to snapshot}
        {--json : Emit the outcomes as JSON}';

    protected $description = 'Snapshot pages with every configured driver and report why any failed';

    public function handle(SnapshotManager $snapshots): int
    {
        $fetchers = $snapshots->all();

        if ($fetchers === []) {
            $this->error('No snapshot driver is configured.');

            return self::FAILURE;
        }

        $report = [];

        foreach ((array) $this->argument('url') as $url) {
            $rows = [];

            foreach ($fetchers as $name => $fetcher) {
                $started = microtime(true);

                try {
                    $snapshot = $fetcher->snapshot($url);
                    $row = [
                        'fetcher' => $name,
                        'outcome' => 'ok',
                        'status_code' => $snapshot->statusCode,
                        'vendor' => null,
                        'detail' => $snapshot->title.' | '.$snapshot->finalUrl,
                    ];
                } catch (SnapshotFailedException $e) {
                    $row = [
                        'fetcher' => $name,
                        'outcome' => $e->failure->value,
                        'status_code' => $e->statusCode,
                        'vendor' => $e->vendor,
                        'detail' => $e->detail,
                    ];
                }

                $row['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
                $rows[] = $row;
            }

            $this->newLine();
            $this->info($url);
            $this->table(
                ['driver', 'outcome', 'status', 'vendor', 'latency', 'detail'],
                array_map(fn (array $r): array => [
                    $r['fetcher'], $r['outcome'], $r['status_code'] ?? '-', $r['vendor'] ?? '-', $r['latency_ms'].'ms', mb_substr($r['detail'], 0, 90),
                ], $rows),
            );
            $this->line('  '.$this->verdict($rows));

            $report[$url] = ['verdict' => $this->verdict($rows), 'drivers' => $rows];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function verdict(array $rows): string
    {
        $outcomes = array_column($rows, 'outcome', 'fetcher');
        $blocked = SnapshotFailure::Blocked->value;

        return match (true) {
            ! in_array('ok', $outcomes, true) && count(array_unique($outcomes)) === 1 && reset($outcomes) === $blocked => 'Every driver was blocked. Run the same URL from another network: blocked there too means the site refuses automated clients, not this address.',
            ($outcomes['crawl4ai'] ?? null) === $blocked && in_array('ok', $outcomes, true) => 'Only the headless browser was blocked: the site fingerprints the browser, not this address.',
            in_array('ok', $outcomes, true) => 'At least one driver succeeded.',
            in_array(SnapshotFailure::Unavailable->value, $outcomes, true) => 'A driver is down or misconfigured; check its URL and token.',
            default => 'No driver succeeded; see each outcome.',
        };
    }
}
