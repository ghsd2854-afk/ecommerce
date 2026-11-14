<?php

namespace Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ReadYourWrites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Notifications\Models\UserNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Replica, except right after this user's order / status change created a notification.
        $notifications = ReadYourWrites::apply(UserNotification::query(), 'user:'.$request->user()->id)
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($notifications);
    }

    public function markRead(Request $request, UserNotification $notification): JsonResponse
    {
        abort_if($notification->user_id !== $request->user()->id, 403, 'You are not allowed to access this notification.');

        $notification->update(['read_at' => now()]);
        ReadYourWrites::remember('user:'.$request->user()->id);

        return response()->json($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        ReadYourWrites::remember('user:'.$request->user()->id);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
