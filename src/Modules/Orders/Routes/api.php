<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

// How {order} is loaded (primary / replica): see OrdersServiceProvider::boot.

Route::prefix('orders')->middleware('auth:api')->group(function () {
    // Personal data: ETag with "private" (only the user's own browser may keep a copy).
    Route::middleware('etag:private')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/stats', [OrderController::class, 'stats'])->middleware('role:admin');
        Route::get('/{order}', [OrderController::class, 'show'])->whereNumber('order');
    });

    Route::post('/', [OrderController::class, 'store']);

    Route::patch('/{order}/status', [OrderController::class, 'updateStatus'])
        ->whereNumber('order')
        ->middleware('role:admin');
});
