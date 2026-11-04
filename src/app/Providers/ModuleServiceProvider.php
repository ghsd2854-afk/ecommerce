<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base class for the service provider of every module.
 *
 * Each module registers ITSELF (routes, migrations, config, bindings, events)
 * through its own provider in Modules/<Name>/Providers. The application only
 * lists those providers in bootstrap/providers.php.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Every module API is served under /api/v1 (version in the path). */
    public const API_PREFIX = 'api/v1';

    /** Absolute path of the module folder, e.g. base_path('Modules/Orders'). */
    abstract protected function modulePath(): string;

    protected function loadModuleRoutes(): void
    {
        $routes = $this->modulePath().'/Routes/api.php';

        if (file_exists($routes) && ! $this->app->routesAreCached()) {
            Route::prefix(self::API_PREFIX)->middleware('api')->group($routes);
        }
    }

    protected function loadModuleMigrations(): void
    {
        $migrations = $this->modulePath().'/Database/Migrations';

        if (is_dir($migrations)) {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
