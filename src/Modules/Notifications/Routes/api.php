<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationController;

// {notification} is loaded from the PRIMARY database (see NotificationsServiceProvider::boot).

Route::prefix('notifications')->middleware('auth:api')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
});
