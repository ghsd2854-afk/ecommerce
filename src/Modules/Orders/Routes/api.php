<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;



//  4:

Broadcast::routes(['middleware' => ['auth:api']]);

Route::prefix('orders')->middleware('auth:api')->group(function () {
   
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