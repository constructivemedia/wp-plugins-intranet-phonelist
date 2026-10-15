<?php

namespace ConstructiveMedia\IntranetPhonelist\Cli;

use ConstructiveMedia\IntranetPhonelist\Oracle\Adapter;
use Throwable;
use WP_CLI;

/**
 * wp phonelist oracle-import [--staff=<id>]
 *
 * Development only: registered when the Oracle credentials are set.
 */
final class OracleImportCommand
{
    public function __construct(private readonly Adapter $adapter)
    {
    }

    public function register(): void
    {
        WP_CLI::add_command('phonelist oracle-import', $this, [
            'shortdesc' => 'Import divisions and staff from Oracle through the ingest API code (development only).',
            'synopsis' => [
                ['type' => 'assoc', 'name' => 'staff', 'optional' => true, 'description' => 'Import only this Oracle staff ID.'],
            ],
        ]);
    }

    public function __invoke(array $args, array $options): void
    {
        $runId = wp_generate_uuid4();
        try {
            $counts = $this->adapter->run($options['staff'] ?? null, $runId, 'oracle', fn (string $step) => WP_CLI::log($step));
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }

        foreach ($counts as $type => $results) {
            ksort($results);
            WP_CLI::log(sprintf('%s: %s', ucfirst($type), implode(', ', array_map(fn (string $result, int $n): string => "$n $result", array_keys($results), $results))));
        }
        WP_CLI::success("Imported (job $runId in the log).");
    }
}
