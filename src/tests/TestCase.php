<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety guard: the tests refresh the database, so they must ONLY run on the
     * in-memory SQLite database from phpunit.xml. If the configuration is cached
     * (php artisan optimize / config:cache), Laravel would ignore phpunit.xml and
     * the tests would wipe the real MySQL database, so we stop instead. Cached routes
     * are refused too, because they may not match the current code.
     */
    public function createApplication()
    {
        // In Docker, docker-compose.yml sets CACHE_STORE=redis as a real environment variable,
        // which wins over phpunit.xml. The tests must never use (or flush) the real Redis of the
        // running shop, so the cache stores are forced to the in-memory "array" store here.
        foreach (['CACHE_STORE' => 'array', 'PERFORMANCE_CACHE_STORE' => 'array'] as $key => $value) {
            $_SERVER[$key] = $_ENV[$key] = $value;
            putenv("$key=$value");
        }

        $app = parent::createApplication();

        if (file_exists($app->getCachedConfigPath()) || file_exists($app->getCachedRoutesPath())) {
            throw new RuntimeException(
                'Configuration or routes are cached. Run "php artisan optimize:clear" before the tests '
                .'(or use: demo.cmd tests). Cached config would point the tests at the real database, '
                .'and cached routes may be out of date.'
            );
        }

        // Static per-request memo of the replica check (a test runs many requests in one process).
        \App\Support\ReadYourWrites::flush();

        return $app;
    }
}
