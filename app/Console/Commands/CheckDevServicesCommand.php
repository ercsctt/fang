<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class CheckDevServicesCommand extends Command
{
    protected $signature = 'dev:check-services';

    protected $description = 'Verify that Docker development services (PostgreSQL, Redis, Meilisearch) are running and accessible';

    public function handle(): int
    {
        info('Checking Development Services');
        $this->newLine();

        $results = [
            $this->checkPostgres(),
            $this->checkRedis(),
            $this->checkMeilisearch(),
        ];

        $this->newLine();
        $this->displaySummary($results);

        $failedCount = count(array_filter($results, fn (array $result): bool => ! $result['success']));

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check PostgreSQL connection.
     *
     * @return array{name: string, success: bool, message: string}
     */
    protected function checkPostgres(): array
    {
        $name = 'PostgreSQL';

        try {
            DB::connection()->getPdo();

            $this->line('  <fg=green>✓</> '.$name.' - Connected successfully');

            return [
                'name' => $name,
                'success' => true,
                'message' => 'Connected successfully',
            ];
        } catch (\Exception $e) {
            $errorMessage = $this->extractErrorMessage($e);
            $this->line('  <fg=red>✗</> '.$name.' - '.$errorMessage);

            return [
                'name' => $name,
                'success' => false,
                'message' => $errorMessage,
            ];
        }
    }

    /**
     * Check Redis connection.
     *
     * @return array{name: string, success: bool, message: string}
     */
    protected function checkRedis(): array
    {
        $name = 'Redis';

        try {
            $response = Redis::ping();

            if ($response === true || $response === 'PONG') {
                $this->line('  <fg=green>✓</> '.$name.' - Connected successfully');

                return [
                    'name' => $name,
                    'success' => true,
                    'message' => 'Connected successfully',
                ];
            }

            $this->line('  <fg=red>✗</> '.$name.' - Unexpected response');

            return [
                'name' => $name,
                'success' => false,
                'message' => 'Unexpected response: '.var_export($response, true),
            ];
        } catch (\Exception $e) {
            $errorMessage = $this->extractErrorMessage($e);
            $this->line('  <fg=red>✗</> '.$name.' - '.$errorMessage);

            return [
                'name' => $name,
                'success' => false,
                'message' => $errorMessage,
            ];
        }
    }

    /**
     * Check Meilisearch connection via health endpoint.
     *
     * @return array{name: string, success: bool, message: string}
     */
    protected function checkMeilisearch(): array
    {
        $name = 'Meilisearch';
        $host = config('scout.meilisearch.host', 'http://127.0.0.1:7700');
        $healthUrl = rtrim($host, '/').'/health';

        try {
            $response = Http::timeout(5)->get($healthUrl);

            if ($response->successful()) {
                $status = $response->json('status');

                if ($status === 'available') {
                    $this->line('  <fg=green>✓</> '.$name.' - Connected successfully');

                    return [
                        'name' => $name,
                        'success' => true,
                        'message' => 'Connected successfully',
                    ];
                }
            }

            $this->line('  <fg=red>✗</> '.$name.' - Health check failed (status: '.$response->status().')');

            return [
                'name' => $name,
                'success' => false,
                'message' => 'Health check failed with status: '.$response->status(),
            ];
        } catch (\Exception $e) {
            $errorMessage = $this->extractErrorMessage($e);
            $this->line('  <fg=red>✗</> '.$name.' - '.$errorMessage);

            return [
                'name' => $name,
                'success' => false,
                'message' => $errorMessage,
            ];
        }
    }

    /**
     * Display summary of all service checks.
     *
     * @param  array<int, array{name: string, success: bool, message: string}>  $results
     */
    protected function displaySummary(array $results): void
    {
        $passed = count(array_filter($results, fn (array $r): bool => $r['success']));
        $total = count($results);

        if ($passed === $total) {
            info('All services are running ('.$passed.'/'.$total.')');
        } else {
            $failed = array_filter($results, fn (array $r): bool => ! $r['success']);
            $failedNames = implode(', ', array_column($failed, 'name'));
            error('Some services need attention ('.$passed.'/'.$total.' passing)');
            $this->newLine();
            $this->line('  Services needing attention: '.$failedNames);
            $this->newLine();
            $this->line('  Make sure Docker containers are running:');
            $this->line('    docker compose up -d');
        }
    }

    /**
     * Extract a user-friendly error message from an exception.
     */
    protected function extractErrorMessage(\Exception $e): string
    {
        $message = $e->getMessage();

        // Simplify common connection errors
        if (str_contains($message, 'Connection refused')) {
            return 'Connection refused - is the service running?';
        }

        if (str_contains($message, 'No such host')) {
            return 'Host not found - check configuration';
        }

        // Truncate long messages
        if (mb_strlen($message) > 100) {
            return mb_substr($message, 0, 97).'...';
        }

        return $message;
    }
}
