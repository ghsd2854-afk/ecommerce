<?php

namespace Modules\Orders\Providers;

use App\Providers\ModuleServiceProvider;
use App\Support\ReadYourWrites;
use Illuminate\Support\Facades\Broadcast;
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

    
        Route::bind('order', function (string $value) {
            if (request()->isMethodSafe()) {
                $order = ReadYourWrites::apply(Order::query(), 'user:'.auth('api')->id())->find($value);
                if ($order) {
                    return $order;
                }
            }

            return Order::query()->useWritePdo()->findOrFail($value);
        });

        ////.4
        Broadcast::channel('admin.orders', fn ($user) => $user->isAdmin());

     
        Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
            return Order::query()
                ->useWritePdo()
                ->whereKey((int) $orderId)
                ->where('user_id', $user->id)
                ->exists();
        });
    }
}