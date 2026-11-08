<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            PermissionCatalog::ensureSeeded();

            return;
        }

        $this->call([
            DemoSeeder::class,
            E2EDataSeeder::class,
            MegaSeeder::class,
            ReconcileSeedFinancialsSeeder::class,
        ]);
    }
}
