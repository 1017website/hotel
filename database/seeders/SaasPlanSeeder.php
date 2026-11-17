<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaasPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'starter',
                'name' => 'Starter',
                'description' => 'Untuk penginapan dengan tim kecil dan satu lokasi.',
                'monthly_price' => 299_000,
                'yearly_price' => 2_990_000,
                'room_limit' => 25,
                'user_limit' => 8,
            ],
            [
                'slug' => 'bisnis',
                'name' => 'Bisnis',
                'description' => 'Untuk hotel dengan tim resepsionis, housekeeping, dan bagian lain.',
                'monthly_price' => 599_000,
                'yearly_price' => 5_990_000,
                'room_limit' => 75,
                'user_limit' => 25,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Untuk hotel dengan lebih banyak kamar dan akun staf.',
                'monthly_price' => 999_000,
                'yearly_price' => 9_990_000,
                'room_limit' => 150,
                'user_limit' => 60,
            ],
        ];

        $now = now();
        DB::table('subscription_plans')->insertOrIgnore(array_map(
            fn (array $plan) => $plan + ['published' => true, 'created_at' => $now, 'updated_at' => $now],
            $plans,
        ));
    }
}
