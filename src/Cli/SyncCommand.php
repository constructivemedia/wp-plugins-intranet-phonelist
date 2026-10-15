<?php

namespace ConstructiveMedia\IntranetPhonelist\Cli;

use ConstructiveMedia\IntranetPhonelist\Sync\Synchronizer;
use WP_CLI;

/**
 * wp phonelist sync [--staff=<id>]
 */
final class SyncCommand
{
    public function __construct(private readonly Synchronizer $synchronizer)
    {
    }

    public function register(): void
    {
        WP_CLI::add_command('phonelist sync', $this, [
            'shortdesc' => 'Sync staff and divisions from Oracle. Exits non-zero unless the run is fully clean.',
            'synopsis' => [[
                'type' => 'assoc',
                'name' => 'staff',
                'optional' => true,
                'description' => 'Sync only this staff ID (exact string). Never deletes anyone else.',
            ]],
        ]);
    }

    public function __invoke(array $args, array $options): void
    {
        $this->synchronizer->onProgress(fn (string $step) => WP_CLI::log($step));
        $result = $this->synchronizer->run('cli', $options['staff'] ?? null);

        $result['status'] === 'completed'
            ? WP_CLI::success($result['message'])
            : WP_CLI::error($result['message']);
    }
}
