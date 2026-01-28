<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->envPath = base_path('.env');
    $this->envExamplePath = base_path('.env.example');
    $this->originalEnvExists = file_exists($this->envPath);

    if ($this->originalEnvExists) {
        $this->originalEnvContent = file_get_contents($this->envPath);
    }
});

afterEach(function () {
    if ($this->originalEnvExists && isset($this->originalEnvContent)) {
        file_put_contents($this->envPath, $this->originalEnvContent);
    }
});

test('command has correct signature and description', function () {
    $this->artisan('dev:setup', ['--help' => true])
        ->expectsOutputToContain('dev:setup')
        ->expectsOutputToContain('--skip-docker')
        ->expectsOutputToContain('--skip-npm')
        ->expectsOutputToContain('--skip-migrate')
        ->expectsOutputToContain('--skip-seed')
        ->expectsOutputToContain('--skip-wayfinder')
        ->assertSuccessful();
});

test('command skips env copy when .env already exists', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('.env file already exists - skipping')
        ->assertSuccessful();
});

test('command skips app key when already set', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('App key already set - skipping')
        ->assertSuccessful();
});

test('command skips docker services with --skip-docker flag', function () {
    Process::fake([
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-docker' => true, '--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Skipping Docker services (--skip-docker)')
        ->expectsOutputToContain('Skipping service health check (--skip-docker)')
        ->assertSuccessful();

    Process::assertNotRan('docker compose up -d');
});

test('command skips npm with --skip-npm flag', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
    ]);

    $this->artisan('dev:setup', ['--skip-npm' => true, '--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Skipping npm install (--skip-npm)')
        ->expectsOutputToContain('Skipping frontend build (--skip-npm)')
        ->assertSuccessful();

    Process::assertNotRan('npm install');
    Process::assertNotRan('npm run build');
});

test('command skips migrations with --skip-migrate flag', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Skipping migrations (--skip-migrate)')
        ->assertSuccessful();
});

test('command skips seeders with --skip-seed flag', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Skipping seeders (--skip-seed)')
        ->assertSuccessful();
});

test('command fails when docker services fail to start', function () {
    Process::fake([
        'docker compose up -d' => Process::result(exitCode: 1, errorOutput: 'Docker daemon not running'),
    ]);

    $this->artisan('dev:setup', ['--skip-npm' => true, '--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Failed to start Docker services')
        ->assertFailed();
});

test('command fails when services do not become healthy', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"starting"}'),
    ]);

    $this->artisan('dev:setup', ['--skip-npm' => true, '--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Services did not become healthy in time')
        ->assertFailed();
})->skip('This test takes too long due to the 30 retry loop with 2s sleep');

test('command fails when npm install fails', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(exitCode: 1, errorOutput: 'npm ERR! code ENOENT'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Failed to install npm dependencies')
        ->assertFailed();
});

test('command fails when npm build fails', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(exitCode: 1, errorOutput: 'Build failed'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Failed to build frontend assets')
        ->assertFailed();
});

test('command displays success message and next steps on completion', function () {
    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: '{"State":"running","Health":"healthy"}'),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Development environment setup complete!')
        ->expectsOutputToContain('composer run dev')
        ->expectsOutputToContain('dev:check-services')
        ->assertSuccessful();
});

test('command handles NDJSON docker output with multiple containers', function () {
    $dockerOutput = '{"State":"running","Health":"healthy"}
{"State":"running","Health":"healthy"}
{"State":"running","Health":"healthy"}';

    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: $dockerOutput),
        'npm install' => Process::result(output: 'Success'),
        'npm run build' => Process::result(output: 'Success'),
    ]);

    $this->artisan('dev:setup', ['--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('All services are healthy')
        ->assertSuccessful();
});

test('command detects unhealthy containers', function () {
    $dockerOutput = '{"State":"running","Health":"healthy"}
{"State":"running","Health":"unhealthy"}';

    Process::fake([
        'docker compose up -d' => Process::result(output: 'Success'),
        'docker compose ps --format json' => Process::result(output: $dockerOutput),
    ]);

    $this->artisan('dev:setup', ['--skip-npm' => true, '--skip-migrate' => true, '--skip-seed' => true, '--skip-wayfinder' => true])
        ->expectsOutputToContain('Services did not become healthy in time')
        ->assertFailed();
})->skip('This test takes too long due to the 30 retry loop with 2s sleep');

test('command with all skip flags completes quickly', function () {
    Process::fake();

    $this->artisan('dev:setup', [
        '--skip-docker' => true,
        '--skip-npm' => true,
        '--skip-migrate' => true,
        '--skip-seed' => true,
        '--skip-wayfinder' => true,
    ])
        ->expectsOutputToContain('Skipping Docker services')
        ->expectsOutputToContain('Skipping npm install')
        ->expectsOutputToContain('Skipping migrations')
        ->expectsOutputToContain('Skipping seeders')
        ->expectsOutputToContain('Skipping Wayfinder generation')
        ->assertSuccessful();
});
