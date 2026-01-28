<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class DevSetupCommand extends Command
{
    protected $signature = 'dev:setup
                            {--skip-docker : Skip starting Docker services}
                            {--skip-npm : Skip npm install and build}
                            {--skip-migrate : Skip running migrations}
                            {--skip-seed : Skip running seeders}
                            {--skip-wayfinder : Skip generating Wayfinder routes}';

    protected $description = 'Initialize the development environment for new developers';

    public function handle(): int
    {
        info('🚀 Setting up Development Environment');
        $this->newLine();

        $steps = [
            ['method' => 'copyEnvFile', 'name' => 'Copy .env file'],
            ['method' => 'generateAppKey', 'name' => 'Generate app key'],
            ['method' => 'startDockerServices', 'name' => 'Start Docker services'],
            ['method' => 'waitForServices', 'name' => 'Wait for services to be healthy'],
            ['method' => 'runMigrations', 'name' => 'Run database migrations'],
            ['method' => 'runSeeders', 'name' => 'Run database seeders'],
            ['method' => 'generateWayfinder', 'name' => 'Generate Wayfinder routes'],
            ['method' => 'installNpmDependencies', 'name' => 'Install npm dependencies'],
            ['method' => 'buildFrontendAssets', 'name' => 'Build frontend assets'],
        ];

        $failedStep = null;

        foreach ($steps as $step) {
            $result = $this->{$step['method']}();

            if ($result === false) {
                $failedStep = $step['name'];
                break;
            }
        }

        $this->newLine();

        if ($failedStep !== null) {
            error('Setup failed at step: '.$failedStep);

            return self::FAILURE;
        }

        $this->displaySuccessMessage();

        return self::SUCCESS;
    }

    protected function copyEnvFile(): bool
    {
        $envPath = base_path('.env');
        $envExamplePath = base_path('.env.example');

        if (file_exists($envPath)) {
            $this->line('  <fg=yellow>⊘</> .env file already exists - skipping');

            return true;
        }

        if (! file_exists($envExamplePath)) {
            $this->line('  <fg=red>✗</> .env.example not found');

            return false;
        }

        copy($envExamplePath, $envPath);
        $this->line('  <fg=green>✓</> Created .env from .env.example');

        return true;
    }

    protected function generateAppKey(): bool
    {
        $envContent = file_get_contents(base_path('.env'));

        if ($envContent === false) {
            $this->line('  <fg=red>✗</> Unable to read .env file');

            return false;
        }

        if (preg_match('/^APP_KEY=.+$/m', $envContent)) {
            $this->line('  <fg=yellow>⊘</> App key already set - skipping');

            return true;
        }

        $result = $this->call('key:generate', ['--no-interaction' => true]);

        if ($result !== 0) {
            $this->line('  <fg=red>✗</> Failed to generate app key');

            return false;
        }

        $this->line('  <fg=green>✓</> Generated app key');

        return true;
    }

    protected function startDockerServices(): bool
    {
        if ($this->option('skip-docker')) {
            $this->line('  <fg=yellow>⊘</> Skipping Docker services (--skip-docker)');

            return true;
        }

        $this->line('  <fg=blue>…</> Starting Docker services...');

        $result = Process::run('docker compose up -d');

        if (! $result->successful()) {
            $this->line('  <fg=red>✗</> Failed to start Docker services');
            $this->line('    '.$this->truncateMessage($result->errorOutput()));

            return false;
        }

        $this->line('  <fg=green>✓</> Docker services started');

        return true;
    }

    protected function waitForServices(): bool
    {
        if ($this->option('skip-docker')) {
            $this->line('  <fg=yellow>⊘</> Skipping service health check (--skip-docker)');

            return true;
        }

        $maxAttempts = 30;
        $attemptInterval = 2;

        $this->line('  <fg=blue>…</> Waiting for services to be healthy...');

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = Process::run('docker compose ps --format json');

            if ($result->successful()) {
                $containers = $this->parseDockerOutput($result->output());

                if ($this->allServicesHealthy($containers)) {
                    $this->line('  <fg=green>✓</> All services are healthy');

                    return true;
                }
            }

            if ($attempt < $maxAttempts) {
                sleep($attemptInterval);
            }
        }

        $this->line('  <fg=red>✗</> Services did not become healthy in time');
        $this->line('    Try running: docker compose ps');

        return false;
    }

    /**
     * Parse Docker Compose output which may be NDJSON (newline-delimited JSON).
     *
     * @return array<int, array{State?: string, Health?: string}>
     */
    protected function parseDockerOutput(string $output): array
    {
        $containers = [];
        $lines = array_filter(explode("\n", trim($output)));

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $containers[] = $decoded;
            }
        }

        return $containers;
    }

    /**
     * Check if all containers are healthy.
     *
     * @param  array<int, array{State?: string, Health?: string}>  $containers
     */
    protected function allServicesHealthy(array $containers): bool
    {
        if (empty($containers)) {
            return false;
        }

        foreach ($containers as $container) {
            $state = $container['State'] ?? '';
            $health = $container['Health'] ?? '';

            if ($state !== 'running') {
                return false;
            }

            if ($health !== '' && $health !== 'healthy') {
                return false;
            }
        }

        return true;
    }

    protected function runMigrations(): bool
    {
        if ($this->option('skip-migrate')) {
            $this->line('  <fg=yellow>⊘</> Skipping migrations (--skip-migrate)');

            return true;
        }

        $this->line('  <fg=blue>…</> Running database migrations...');

        $result = $this->call('migrate', ['--no-interaction' => true, '--force' => true]);

        if ($result !== 0) {
            $this->line('  <fg=red>✗</> Failed to run migrations');

            return false;
        }

        $this->line('  <fg=green>✓</> Migrations completed');

        return true;
    }

    protected function runSeeders(): bool
    {
        if ($this->option('skip-seed')) {
            $this->line('  <fg=yellow>⊘</> Skipping seeders (--skip-seed)');

            return true;
        }

        $this->line('  <fg=blue>…</> Running database seeders...');

        $result = $this->call('db:seed', ['--no-interaction' => true, '--force' => true]);

        if ($result !== 0) {
            $this->line('  <fg=red>✗</> Failed to run seeders');

            return false;
        }

        $this->line('  <fg=green>✓</> Seeders completed');

        return true;
    }

    protected function generateWayfinder(): bool
    {
        if ($this->option('skip-wayfinder')) {
            $this->line('  <fg=yellow>⊘</> Skipping Wayfinder generation (--skip-wayfinder)');

            return true;
        }

        $this->line('  <fg=blue>…</> Generating Wayfinder routes...');

        $result = $this->call('wayfinder:generate', ['--no-interaction' => true]);

        if ($result !== 0) {
            $this->line('  <fg=red>✗</> Failed to generate Wayfinder routes');

            return false;
        }

        $this->line('  <fg=green>✓</> Wayfinder routes generated');

        return true;
    }

    protected function installNpmDependencies(): bool
    {
        if ($this->option('skip-npm')) {
            $this->line('  <fg=yellow>⊘</> Skipping npm install (--skip-npm)');

            return true;
        }

        $this->line('  <fg=blue>…</> Installing npm dependencies...');

        $result = Process::timeout(300)->run('npm install');

        if (! $result->successful()) {
            $this->line('  <fg=red>✗</> Failed to install npm dependencies');
            $this->line('    '.$this->truncateMessage($result->errorOutput()));

            return false;
        }

        $this->line('  <fg=green>✓</> npm dependencies installed');

        return true;
    }

    protected function buildFrontendAssets(): bool
    {
        if ($this->option('skip-npm')) {
            $this->line('  <fg=yellow>⊘</> Skipping frontend build (--skip-npm)');

            return true;
        }

        $this->line('  <fg=blue>…</> Building frontend assets...');

        $result = Process::timeout(300)->run('npm run build');

        if (! $result->successful()) {
            $this->line('  <fg=red>✗</> Failed to build frontend assets');
            $this->line('    '.$this->truncateMessage($result->errorOutput()));

            return false;
        }

        $this->line('  <fg=green>✓</> Frontend assets built');

        return true;
    }

    protected function displaySuccessMessage(): void
    {
        info('✅ Development environment setup complete!');
        $this->newLine();
        $this->line('  <fg=cyan>Next steps:</>');
        $this->line('    • Start the development server: <fg=yellow>composer run dev</>');
        $this->line('    • Check service status: <fg=yellow>php artisan dev:check-services</>');
        $this->line('    • View your app at: <fg=yellow>http://localhost:8000</>');
    }

    protected function truncateMessage(string $message): string
    {
        $message = trim($message);
        $firstLine = strtok($message, "\n") ?: $message;

        if (mb_strlen($firstLine) > 80) {
            return mb_substr($firstLine, 0, 77).'...';
        }

        return $firstLine;
    }
}
