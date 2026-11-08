<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Historic accounting heal is an explicit command, not a migration side effect:
 * php artisan accounting:remediate-historic
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentionally empty. Business repair belongs in the artisan command.
    }

    public function down(): void
    {
        // Non-destructive heal; no automatic reverse of posted journals.
    }
};
