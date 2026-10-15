<?php

namespace ConstructiveMedia\IntranetPhonelist\Cli;

use ConstructiveMedia\IntranetPhonelist\Ingest\Jobs;
use WP_CLI;

/**
 * wp phonelist job <id>
 *
 * Runs a waiting job now, or retries a failed one, and prints its results.
 */
final class JobCommand
{
    public function __construct(private readonly Jobs $jobs)
    {
    }

    public function register(): void
    {
        WP_CLI::add_command('phonelist job', $this, [
            'shortdesc' => 'Run a waiting batch or snapshot job now, or retry a failed one.',
            'synopsis' => [
                ['type' => 'positional', 'name' => 'id', 'description' => 'The job ID (from the Jobs tab or the API reply).'],
            ],
        ]);
    }

    public function __invoke(array $args): void
    {
        $job = $this->jobs->get($args[0]);
        if (!$job) {
            WP_CLI::error('No job with this ID.');
        }
        if (!$this->jobs->process($args[0])) {
            WP_CLI::error("Not run: the job is {$job['status']}, or another job is running.");
        }

        $job = $this->jobs->get($args[0]);
        WP_CLI::log(Jobs::summary($job['results']));
        $job['status'] === 'done' ? WP_CLI::success('Done.') : WP_CLI::error("Job {$job['status']}: {$job['error']}");
    }
}
