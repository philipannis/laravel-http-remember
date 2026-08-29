<?php

namespace PhilipAnnis\HttpRemember\Tests;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use PhilipAnnis\HttpRemember\HttpRememberServiceProvider;

/**
 * Boot an isolated Laravel application for package integration tests.
 */
abstract class TestCase extends Orchestra
{
    /**
     * The package's default fresh threshold in seconds.
     */
    private const DEFAULT_FRESH_SECONDS = 1800;

    /**
     * The package's default total lifetime in seconds.
     */
    private const DEFAULT_LIFETIME_SECONDS = 3600;

    /**
     * The package's default deferred refresh timeout in seconds.
     */
    private const DEFAULT_REFRESH_TIMEOUT_SECONDS = 15;

    /**
     * Prevent every package test from reaching the network.
     */
    protected function setUp(): void
    {
        // Boot the isolated Laravel application and package provider.
        parent::setUp();

        // Turn any incomplete fake definition into an immediate test failure.
        Http::preventStrayRequests();
    }

    /**
     * Register the package service provider.
     *
     * @param  mixed  $app  The Testbench application.
     * @return array<int, class-string> The package providers.
     */
    protected function getPackageProviders($app): array
    {
        // Register the provider that defines the remember macro and configuration.
        return [
            HttpRememberServiceProvider::class,
        ];
    }

    /**
     * Configure the package for deterministic in-memory tests.
     *
     * @param  mixed  $app  The Testbench application.
     */
    protected function defineEnvironment($app): void
    {
        // Keep every test independent from databases and external cache services.
        $app['config']->set('cache.default', 'array');

        // Use the documented package defaults unless a test overrides them.
        $app['config']->set('http-remember.ttl', [self::DEFAULT_FRESH_SECONDS, self::DEFAULT_LIFETIME_SECONDS]);
        $app['config']->set('http-remember.store', null);
        $app['config']->set('http-remember.refresh_timeout', self::DEFAULT_REFRESH_TIMEOUT_SECONDS);
    }

    /**
     * Restore global time state after every test.
     */
    protected function tearDown(): void
    {
        // Prevent a frozen clock from affecting another test.
        Carbon::setTestNow();

        // Complete Testbench's normal application cleanup.
        parent::tearDown();
    }
}
