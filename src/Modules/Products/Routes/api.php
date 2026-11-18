<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductController;

// {product} is loaded from the PRIMARY database (see ProductsServiceProvider::boot).

Route::prefix('products')->group(function () {
    // Public catalog: Redis cache + ETag (304 Not Modified when nothing changed).
    Route::middleware('etag:public')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/categories', [ProductController::class, 'categories']);
        Route::get('/{id}', [ProductController::class, 'show'])->whereNumber('id');
    });

    Route::middleware(['auth:api', 'role:admin'])->group(function () {
        Route::post('/', [ProductController::class, 'store']);
        Route::match(['put', 'patch', 'post'], '/{product}', [ProductController::class, 'update']);
        Route::delete('/{product}', [ProductController::class, 'destroy']);
    });
});
