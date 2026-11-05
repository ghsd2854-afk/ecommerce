<?php

namespace Modules\Notifications\Services;

use Modules\Notifications\Models\UserNotification;
use Modules\Users\Models\User;

class NotificationService
{
    public function notify(int $userId, string $type, string $message): void
    {
        UserNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'message' => $message,
        ]);
    }

    public function notifyAdmins(string $type, string $message): void
    {
        User::where('role', 'admin')->pluck('id')->each(
            fn (int $id) => $this->notify($id, $type, $message)
        );
    }
}
