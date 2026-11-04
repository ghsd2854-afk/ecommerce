<?php

namespace Modules\Notifications\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Modules\Notifications\Listeners\SendOrderPlacedNotifications;
use Modules\Notifications\Listeners\SendOrderStatusNotification;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Events\OrderStatusChanged;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();

        Event::listen(OrderPlaced::class, SendOrderPlacedNotifications::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusNotification::class);
    }
}
