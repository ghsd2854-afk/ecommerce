<?php

namespace Modules\Products\Providers;

use App\Providers\ModuleServiceProvider;
use Modules\Products\Contracts\ProductCatalog;
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
    }
}
