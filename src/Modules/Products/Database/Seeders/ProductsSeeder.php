<?php

namespace Modules\Products\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Models\Product;

class ProductsSeeder extends Seeder
{
    public function run(): void
    {
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
