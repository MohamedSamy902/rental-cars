<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_settings')) {
            return;
        }

        $rows = DB::table('tenant_settings')->select(['id', 'operational'])->get();
        foreach ($rows as $row) {
            $ops = json_decode((string) ($row->operational ?? ''), true);
            if (! is_array($ops) || ! array_key_exists('default_contract_vehicle_value', $ops)) {
                continue;
            }
            unset($ops['default_contract_vehicle_value']);
            DB::table('tenant_settings')->where('id', $row->id)->update([
                'operational' => json_encode($ops, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // لا نسترجع القيمة المحذوفة.
    }
};
