<?php

namespace Modules\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Mail\OrderStatusMail;
use Modules\Orders\Events\OrderStatusChanged;
use Modules\Users\Contracts\UserDirectory;
use Throwable;

class SendOrderStatusEmail extends QueuedMailListener
{
    public function __construct(private UserDirectory $users)
    {
    }

    public function handle(OrderStatusChanged $event): void
    {
        $this->failIfSimulated();

        $email = $this->users->emailOf($event->userId);

        if ($email === null) {
            return;
        }

        Mail::to($email)->send(new OrderStatusMail($event->orderId, $event->status));
    }

    public function failed(OrderStatusChanged $event, Throwable $e): void
    {
        Log::critical("Order #{$event->orderId}: the status e-mail could not be sent: {$e->getMessage()}");
    }
}