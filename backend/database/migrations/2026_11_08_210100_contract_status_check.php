<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL/MariaDB reject a contract status outside the lifecycle.
 * SQLite test databases keep the same rule in the service layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE contracts ADD CONSTRAINT contracts_status_allowed CHECK (status in ('pending','approved','active','completed','cancelled'))"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_status_allowed');
    }
};
