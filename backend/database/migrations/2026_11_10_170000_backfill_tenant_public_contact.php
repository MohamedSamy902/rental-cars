<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * بطاقة QR للزائر تحتاج هاتف/عنوان المركز. إن كانت فارغة في operational نعبّئ القيم المعتمدة للطباعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_settings')) {
            return;
        }

        $defaults = [
            'print_phone' => (string) config('rpm.center_public_phone', '01125253888'),
            'print_address' => (string) config(
                'rpm.center_public_address',
                '17 شارع كورنيش النيل - حدائق حلوان - محافظة القاهرة'
            ),
            'company_tagline' => (string) config('rpm.center_public_tagline', 'لخدمات رجال الأعمال'),
        ];

        $rows = DB::table('tenant_settings')->select(['id', 'operational'])->get();
        foreach ($rows as $row) {
            $ops = [];
            if (is_string($row->operational) && $row->operational !== '') {
                $decoded = json_decode($row->operational, true);
                $ops = is_array($decoded) ? $decoded : [];
            } elseif (is_array($row->operational)) {
                $ops = $row->operational;
            }

            $changed = false;
            foreach ($defaults as $key => $value) {
                $current = $ops[$key] ?? null;
                if (! is_string($current) || trim($current) === '') {
                    $ops[$key] = $value;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('tenant_settings')->where('id', $row->id)->update([
                    'operational' => json_encode($ops, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // لا نمسح بيانات الاتصال بعد التعبئة — قد تكون عدّلت يدوياً.
    }
};
