<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Demo فقط افتراضياً — E2E/Mega عبر SEED_PROFILE=e2e|mega|full (ممنوع على الإنتاج بالخطأ).
        $profile = strtolower((string) env('SEED_PROFILE', 'demo'));

        $this->call([DemoSeeder::class]);

        if (in_array($profile, ['e2e', 'full'], true)) {
            $this->call([E2EDataSeeder::class]);
        }

        if (in_array($profile, ['mega', 'full'], true)) {
            $this->call([MegaSeeder::class]);
        }
    }
}
