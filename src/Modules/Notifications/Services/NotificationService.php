<?php

namespace Modules\Notifications\Services;

use Modules\Notifications\Models\UserNotification;
use Modules\Users\Contracts\UserDirectory;

class NotificationService
{
    public function __construct(private UserDirectory $users)
    {
    }

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
        foreach ($this->users->adminIds() as $id) {
            $this->notify($id, $type, $message);
        }
    }
}
