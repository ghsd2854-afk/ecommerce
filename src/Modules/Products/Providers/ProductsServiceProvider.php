<?php

namespace Modules\Products\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Products\Contracts\ProductCatalog;
use Modules\Products\Models\Product;
use Modules\Products\Services\EloquentProductCatalog;

class ProductsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    public function register(): void
    {
        $this->app->singleton(ProductCatalog::class, EloquentProductCatalog::class);
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();

        // Route model binding is registered here, not in Routes/api.php: with "php artisan optimize"
        // (route cache) the routes file is not loaded, but the provider always runs.
        // {product} is only used by admin writes: loaded from the PRIMARY database, because a
        // product created a moment ago may not be on the read replica yet (false 404).
        Route::bind('product', fn (string $value) => Product::query()->useWritePdo()->findOrFail($value));
    }
}
