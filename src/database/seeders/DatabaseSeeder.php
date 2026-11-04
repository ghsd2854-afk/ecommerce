<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Models\Product;
use Modules\Users\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Admin', 'admin@shop.test', 'Admin12345', 'admin'],
            ['Test Customer', 'customer@shop.test', 'Customer12345', 'customer'],
            ['Second Customer', 'customer2@shop.test', 'Customer12345', 'customer'],
        ];

        foreach ($accounts as [$name, $email, $password, $role]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'email_verified_at' => now(),
            ])->save();
        }

        $products = [
            ['Wireless Mouse', 'Ergonomic 2.4GHz wireless mouse', 19.99, 'Accessories'],
            ['Mechanical Keyboard', 'RGB mechanical keyboard, blue switches', 59.90, 'Accessories'],
            ['USB-C Hub', '7-in-1 USB-C hub with HDMI', 34.50, 'Accessories'],
            ['27" Monitor', 'Full HD IPS monitor', 189.00, 'Displays'],
            ['Laptop Stand', 'Adjustable aluminium laptop stand', 24.00, 'Office'],
            ['Desk Lamp', 'LED desk lamp with dimmer', 15.75, 'Office'],
            ['Noise Cancelling Headphones', 'Bluetooth over-ear headphones', 129.99, 'Audio'],
            ['Bluetooth Speaker', 'Portable waterproof speaker', 44.00, 'Audio'],
        ];

        foreach ($products as [$name, $description, $price, $category]) {
            Product::firstOrCreate(
                ['name' => $name],
                ['description' => $description, 'price' => $price, 'category' => $category]
            );
        }
    }
}
