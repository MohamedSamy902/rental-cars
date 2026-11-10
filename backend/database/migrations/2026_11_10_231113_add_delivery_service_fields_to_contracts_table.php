<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('has_delivery_service')->default(false)->after('other_fees_description');
            $table->unsignedInteger('delivery_service_fee')->default(0)->after('has_delivery_service');
            $table->string('delivery_service_notes', 255)->nullable()->after('delivery_service_fee');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'has_delivery_service',
                'delivery_service_fee',
                'delivery_service_notes',
            ]);
        });
    }
};
