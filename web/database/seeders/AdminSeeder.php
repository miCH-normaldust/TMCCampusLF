<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) env('TMC_DEFAULT_ADMIN_PASSWORD');
        if (strlen($password) < 12) {
            throw new RuntimeException('Set TMC_DEFAULT_ADMIN_PASSWORD to a value of at least 12 characters before seeding.');
        }

        User::updateOrCreate(
            ['email' => strtolower((string) env('TMC_DEFAULT_ADMIN_EMAIL', 'admin@tmc.test'))],
            [
                'first_name' => 'CampusLF',
                'last_name' => 'Administrator',
                'tmc_id_no' => 'TMC-ADMIN-001',
                'password' => $password,
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
            ],
        );
    }
}
