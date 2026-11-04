<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Users\Models\User;

class UsersSeeder extends Seeder
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
    }
}
