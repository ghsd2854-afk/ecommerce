<?php

namespace Modules\Orders\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Orders\Listeners\PreventDeletingOrderedProduct;
use Modules\Products\Events\ProductDeleting;

class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();

        Event::listen(ProductDeleting::class, PreventDeletingOrderedProduct::class);
    }
}
