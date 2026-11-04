<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('owner_agreement_starts_on')->nullable()->after('owner_since');
            $table->date('owner_agreement_ends_on')->nullable()->after('owner_agreement_starts_on');
            $table->string('owner_agreement_number', 100)->nullable()->after('owner_agreement_ends_on');
            $table->text('owner_agreement_notes')->nullable()->after('owner_agreement_number');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'owner_agreement_starts_on',
                'owner_agreement_ends_on',
                'owner_agreement_number',
                'owner_agreement_notes',
            ]);
        });
    }
};
