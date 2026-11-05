<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModulesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $modulesPath = base_path('Modules');

        foreach (glob($modulesPath . '/*', GLOB_ONLYDIR) as $modulePath) {
            $routes = $modulePath . '/Routes/api.php';
            if (file_exists($routes)) {
                Route::prefix('api')->middleware('api')->group($routes);
            }

            $migrations = $modulePath . '/Database/Migrations';
            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }
        }
    }
}
