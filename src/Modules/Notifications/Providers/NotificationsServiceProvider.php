<?php

namespace Modules\Notifications\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\Notifications\Listeners\SendOrderPlacedNotifications;
use Modules\Notifications\Listeners\SendOrderStatusNotification;
use Modules\Notifications\Models\UserNotification;
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

        // Notifications subscribes to events published by other modules.
        Event::listen(OrderPlaced::class, SendOrderPlacedNotifications::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusNotification::class);

        // Route model binding is registered here, not in Routes/api.php: with "php artisan optimize"
        // (route cache) the routes file is not loaded, but the provider always runs.
        // {notification} is only used to mark it as read (a write): loaded from the PRIMARY
        // (a notification created a moment ago may not be on the read replica yet).
        Route::bind('notification', fn (string $value) => UserNotification::query()->useWritePdo()->findOrFail($value));
    }
}
