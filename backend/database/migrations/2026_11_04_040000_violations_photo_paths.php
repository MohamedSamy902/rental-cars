<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            if (! Schema::hasColumn('violations', 'photo_paths')) {
                $table->json('photo_paths')->nullable()->after('photo_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            if (Schema::hasColumn('violations', 'photo_paths')) {
                $table->dropColumn('photo_paths');
            }
        });
    }
};
