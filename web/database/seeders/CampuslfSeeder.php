<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CampuslfSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Phone', 'Laptop', 'Accessory', 'ID/Wallet', 'Bag', 'Books/Supplies', 'Clothing', 'Other'] as $name) {
            DB::table('categories')->updateOrInsert(['name' => $name], ['is_active' => true]);
        }

        foreach ([
            'Main Building',
            'Library',
            'Cafeteria',
            'Science Laboratory',
            'Computer Laboratory',
            'Auditorium',
            'Sports Complex',
            'Student Services',
        ] as $name) {
            DB::table('locations')->updateOrInsert(['name' => $name], ['is_active' => true]);
        }

        foreach ([
            ['name' => 'Security Office', 'description' => 'Campus security office', 'operating_hours' => 'Weekdays, 8:00 AM - 5:00 PM'],
            ['name' => 'Student Help Desk', 'description' => 'Student services help desk', 'operating_hours' => 'Weekdays, 8:00 AM - 5:00 PM'],
        ] as $location) {
            DB::table('meetup_locations')->updateOrInsert(['name' => $location['name']], $location + ['is_active' => true]);
        }

        foreach ([
            'posts_per_day' => '5',
            'claim_attempts' => '3',
            'claims_per_day' => '5',
            'comments_per_day' => '30',
            'reports_per_day' => '5',
            'report_threshold' => '3',
            'archive_days' => '30',
            'handover_code_minutes' => '30',
            'blocked_words' => '',
            'tmc_email_domain' => 'gmail.com',
            'disclaimer' => 'TMC CampusLF helps members connect and does not keep, guarantee, or insure items. Report valuable items to the official TMC office. Do not arrange payments or rewards through this service.',
        ] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        }
    }
}
