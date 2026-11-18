<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;

Route::get('/users/ping', function () {
    return response()->json(['module' => 'users', 'status' => 'ok']);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:10,1,verify');
    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])->middleware('throttle:3,1,resend');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:3,1,forgot');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1,reset');

    Route::middleware('auth:api')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
