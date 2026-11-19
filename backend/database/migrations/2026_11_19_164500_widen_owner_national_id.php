<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen vehicle_owners.national_id so passport / foreign IDs (letters + digits) fit.
 * Live-safe: ALTER COLUMN length only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicle_owners') || ! Schema::hasColumn('vehicle_owners', 'national_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite ignores length; nothing to do.
            return;
        }

        DB::statement('ALTER TABLE vehicle_owners MODIFY national_id VARCHAR(80) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicle_owners') || ! Schema::hasColumn('vehicle_owners', 'national_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE vehicle_owners MODIFY national_id VARCHAR(30) NULL');
    }
};
