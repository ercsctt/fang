<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

test('command returns success when all services are running', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('PostgreSQL - Connected successfully')
        ->expectsOutputToContain('Redis - Connected successfully')
        ->expectsOutputToContain('Meilisearch - Connected successfully')
        ->expectsOutputToContain('All services are running')
        ->assertExitCode(0);
});

test('command returns failure when postgresql is not available', function () {
    DB::shouldReceive('connection->getPdo')->once()->andThrow(new Exception('Connection refused'));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('PostgreSQL - Connection refused')
        ->expectsOutputToContain('Some services need attention')
        ->expectsOutputToContain('PostgreSQL')
        ->assertExitCode(1);
});

test('command returns failure when redis is not available', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andThrow(new Exception('Connection refused'));
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('Redis - Connection refused')
        ->expectsOutputToContain('Some services need attention')
        ->expectsOutputToContain('Redis')
        ->assertExitCode(1);
});

test('command returns failure when meilisearch is not available', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake([
        '*/health' => Http::response([], 500),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('Meilisearch - Health check failed')
        ->expectsOutputToContain('Some services need attention')
        ->expectsOutputToContain('Meilisearch')
        ->assertExitCode(1);
});

test('command returns failure when meilisearch connection fails', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake(function () {
        throw new Exception('Connection refused');
    });

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('Meilisearch - Connection refused')
        ->expectsOutputToContain('Some services need attention')
        ->assertExitCode(1);
});

test('command returns failure when all services are down', function () {
    DB::shouldReceive('connection->getPdo')->once()->andThrow(new Exception('Connection refused'));
    Redis::shouldReceive('ping')->once()->andThrow(new Exception('Connection refused'));
    Http::fake(function () {
        throw new Exception('Connection refused');
    });

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('PostgreSQL - Connection refused')
        ->expectsOutputToContain('Redis - Connection refused')
        ->expectsOutputToContain('Meilisearch - Connection refused')
        ->expectsOutputToContain('Some services need attention')
        ->assertExitCode(1);
});

test('command shows docker compose instruction when services fail', function () {
    DB::shouldReceive('connection->getPdo')->once()->andThrow(new Exception('Connection refused'));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('docker compose up -d')
        ->assertExitCode(1);
});

test('command handles redis returning true instead of PONG', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andReturn(true);
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('Redis - Connected successfully')
        ->assertExitCode(0);
});

test('command reports partial success correctly', function () {
    DB::shouldReceive('connection->getPdo')->once()->andReturn(new PDO('sqlite::memory:'));
    Redis::shouldReceive('ping')->once()->andThrow(new Exception('Connection refused'));
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('2/3')
        ->assertExitCode(1);
});

test('command truncates long error messages', function () {
    $longMessage = str_repeat('x', 200);
    DB::shouldReceive('connection->getPdo')->once()->andThrow(new Exception($longMessage));
    Redis::shouldReceive('ping')->once()->andReturn('PONG');
    Http::fake([
        '*/health' => Http::response(['status' => 'available'], 200),
    ]);

    $this->artisan('dev:check-services')
        ->expectsOutputToContain('...')
        ->assertExitCode(1);
});
