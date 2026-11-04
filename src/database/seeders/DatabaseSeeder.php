<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Products\Database\Seeders\ProductsSeeder;
use Modules\Users\Database\Seeders\UsersSeeder;

class DatabaseSeeder extends Seeder
{
    /** Each module seeds its own data. */
    public function run(): void
    {
        $this->call([
            UsersSeeder::class,
            ProductsSeeder::class,
        ]);
    }
}
