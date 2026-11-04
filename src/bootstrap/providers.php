<?php

use App\Providers\AppServiceProvider;
use Modules\Inventory\Providers\InventoryServiceProvider;
use Modules\Notifications\Providers\NotificationsServiceProvider;
use Modules\Orders\Providers\OrdersServiceProvider;
use Modules\Products\Providers\ProductsServiceProvider;
use Modules\Users\Providers\UsersServiceProvider;

return [
    AppServiceProvider::class,

    // One provider per module: each module registers its own routes, migrations,
    // config, contract bindings and event listeners.
    UsersServiceProvider::class,
    ProductsServiceProvider::class,
    InventoryServiceProvider::class,
    OrdersServiceProvider::class,
    NotificationsServiceProvider::class,
];
