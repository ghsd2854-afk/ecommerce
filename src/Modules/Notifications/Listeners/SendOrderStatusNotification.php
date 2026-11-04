<?php

namespace Modules\Notifications\Listeners;

use Modules\Notifications\Services\NotificationService;
use Modules\Orders\Events\OrderStatusChanged;

class SendOrderStatusNotification
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function handle(OrderStatusChanged $event): void
    {
        $this->notifications->notify($event->userId, 'order_status', "Your order #{$event->orderId} is now {$event->status}.");
    }
}
