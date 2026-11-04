<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductController;

Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/categories', [ProductController::class, 'categories']);
    Route::get('/{product}', [ProductController::class, 'show']);

    Route::middleware(['auth:api', 'role:admin'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::match(['put', 'patch', 'post'], '/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
    });
});
