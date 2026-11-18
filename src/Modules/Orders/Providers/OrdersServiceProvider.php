<?php

namespace Modules\Orders\Providers;

use App\Providers\ModuleServiceProvider;
use App\Support\ReadYourWrites;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Modules\Orders\Listeners\PreventDeletingOrderedProduct;
use Modules\Orders\Models\Order;
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

        // Route model binding is registered here, not in Routes/api.php: with "php artisan optimize"
        // (route cache) the routes file is not loaded, but the provider always runs.
        // {order}: writes (status change) read the primary; reads use the replica, except right
        // after this user wrote (read-your-writes), e.g. the order they just placed.
        // auth('api'): bindings can run before the auth middleware has set the default guard.
        // Not found on the replica -> asked once more on the primary before answering 404
        // (an order placed a moment ago by ANOTHER user, e.g. seen by the admin).
        Route::bind('order', function (string $value) {
            if (request()->isMethodSafe()) {
                $order = ReadYourWrites::apply(Order::query(), 'user:'.auth('api')->id())->find($value);
                if ($order) {
                    return $order;
                }
            }

            return Order::query()->useWritePdo()->findOrFail($value);
        });
    }
}
