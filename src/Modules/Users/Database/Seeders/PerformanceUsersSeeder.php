<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Requirement 3: many fake customers for the load test (perf-1@shop.test ... perf-N@shop.test,
 * password: Password123). Bulk INSERTs of 1000 rows: much faster than creating models one by one.
 */
class PerformanceUsersSeeder
{
    public const EMAIL_PATTERN = 'perf-%@shop.test';

    public const PASSWORD = 'Password123';

    /** @return array<int, int> ids of all performance customers */
    public function seed(int $count): array
    {
        $existing = DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->count();
        $hash = Hash::make(self::PASSWORD); // hashed once (bcrypt is slow on purpose)
        $now = now();

        for ($start = $existing + 1; $start <= $count; $start += 1000) {
            $rows = [];
            for ($i = $start; $i < min($start + 1000, $count + 1); $i++) {
                $rows[] = [
                    'name' => "Perf Customer $i",
                    'email' => "perf-$i@shop.test",
                    'password' => $hash,
                    'role' => 'customer',
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('users')->insert($rows);
        }

        return DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, int> ids of the performance customers (before they are deleted) */
    public function ids(): array
    {
        return DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function clean(): int
    {
        return DB::table('users')->where('email', 'like', self::EMAIL_PATTERN)->delete();
    }
}
