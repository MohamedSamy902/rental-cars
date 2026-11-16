<?php

namespace App\Console\Commands;

use App\Models\TenantSetting;
use Illuminate\Console\Command;

/**
 * يضبط أرضية احتساب مستحقات الملاك (تاريخ تشغيل لمرة واحدة) دون مسّ بداية عقد الإدارة.
 * ليس «كل نوفمبر» — القيمة ثابتة (مثل 2026-11-01) وتتراكم الشهور بعدها عبر السنين.
 * آمن للإعادة: لا يكتب إن كانت القيمة موجودة أصلاً ما لم يُمرَّر --force.
 */
class EnsureOwnerDuesCountsFromCommand extends Command
{
    protected $signature = 'owners:ensure-dues-counts-from
                            {date=2026-11-01 : تاريخ بداية الاحتساب Y-m-d}
                            {--force : استبدال القيمة الحالية}';

    protected $description = 'Ensure tenant operational.owner_dues_counts_from for go-live owner overdue';

    public function handle(): int
    {
        $date = (string) $this->argument('date');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('صيغة التاريخ يجب أن تكون Y-m-d');

            return self::FAILURE;
        }

        $settings = TenantSetting::query()->firstOrCreate([]);
        $ops = is_array($settings->operational) ? $settings->operational : [];
        $existing = $ops['owner_dues_counts_from'] ?? null;
        if (is_string($existing) && $existing !== '' && ! $this->option('force')) {
            $this->info("موجود مسبقاً: owner_dues_counts_from={$existing}");

            return self::SUCCESS;
        }

        $ops['owner_dues_counts_from'] = $date;
        $settings->operational = $ops;
        $settings->save();
        $this->info("تم ضبط owner_dues_counts_from={$date}");

        return self::SUCCESS;
    }
}
