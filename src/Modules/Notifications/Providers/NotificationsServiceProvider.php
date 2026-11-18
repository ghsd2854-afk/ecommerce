<?php

namespace Modules\Notifications\Providers;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\Notifications\Listeners\SendOrderConfirmationEmail;
use Modules\Notifications\Listeners\SendOrderPlacedNotifications;
use Modules\Notifications\Listeners\SendOrderStatusEmail;
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

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/Config/notifications.php', 'notifications');
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->loadModuleMigrations();
        $this->loadViewsFrom($this->modulePath().'/Resources/views', 'notifications');

       
        Event::listen(OrderPlaced::class, SendOrderPlacedNotifications::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusNotification::class);

        // 4
     
        Event::listen(OrderPlaced::class, SendOrderConfirmationEmail::class);
        Event::listen(OrderStatusChanged::class, SendOrderStatusEmail::class);

    
        Route::bind('notification', fn (string $value) => UserNotification::query()->useWritePdo()->findOrFail($value));
    }
}