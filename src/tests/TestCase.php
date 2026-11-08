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
        $app = parent::createApplication();

        if (file_exists($app->getCachedConfigPath()) || file_exists($app->getCachedRoutesPath())) {
            throw new RuntimeException(
                'Configuration or routes are cached. Run "php artisan optimize:clear" before the tests '
                .'(or use: demo.cmd tests). Cached config would point the tests at the real database, '
                .'and cached routes may be out of date.'
            );
        }

        return $app;
    }
}
