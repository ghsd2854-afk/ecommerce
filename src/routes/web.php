<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web pages (Blade)
|--------------------------------------------------------------------------
| These routes only return HTML pages. They contain no business logic and
| never touch the database: every page loads and changes its data by calling
| the REST API (/api/v1/...) from JavaScript, with the user's JWT, exactly
| like Postman does. All security checks stay in the API.
*/

Route::redirect('/', '/products');

// Authentication
Route::view('/login', 'pages.login')->name('login');
Route::view('/register', 'pages.register');
Route::view('/verify-email', 'pages.verify');
Route::view('/forgot-password', 'pages.forgot');

// Shop
Route::view('/products', 'pages.products');
Route::get('/products/{id}', fn (int $id) => view('pages.product', ['id' => $id]))->whereNumber('id');
Route::view('/cart', 'pages.cart');
Route::view('/orders', 'pages.orders');
Route::get('/orders/{id}', fn (int $id) => view('pages.order', ['id' => $id]))->whereNumber('id');
Route::view('/notifications', 'pages.notifications');

// Admin
Route::view('/admin/products', 'pages.admin-products');
Route::view('/admin/orders', 'pages.admin-orders');
