<?php

namespace App\Console\Commands;

use App\Models\VehicleOwner;
use App\Services\Fleet\VehicleOwnerService;
use DomainException;
use Illuminate\Console\Command;

class AccrueOwnerDuesCommand extends Command
{
    protected $signature = 'owners:accrue-dues
                            {--owner= : معرف مالك واحد}
                            {--through= : شهر YYYY-MM (افتراضي الشهر الحالي)}
                            {--repair : تصحيح مبالغ الأشهر غير المقفلة لتطابق أيام العقد}
                            {--reconcile : عكس استحقاقات خارج الجدول (شهر غير مكتمل / قبل الاتفاقية)}';

    protected $description = 'ترحيل استحقاق ملاك العربيات بشكل صريح ومُدقَّق (لا يُستدعى من GET)';

    public function handle(VehicleOwnerService $owners): int
    {
        $through = $this->option('through') ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $through)) {
            $this->error('صيغة --through يجب أن تكون YYYY-MM');

            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair');
        $reconcile = (bool) $this->option('reconcile');
        $query = VehicleOwner::query()->where('is_active', true)->orderBy('id');
        if ($id = $this->option('owner')) {
            $query->whereKey((int) $id);
        }

        $count = 0;
        $changed = 0;
        $failed = 0;
        foreach ($query->cursor() as $owner) {
            try {
                if ($reconcile) {
                    $n = $owners->reconcileAccrualsToSchedule($owner, null);
                } else {
                    $n = $owners->ensureAccruedThrough($owner, $through, null, $repair);
                }
                $count++;
                $changed += $n;
                $this->line("· مالك #{$owner->id} {$owner->full_name}".($n ? " · {$n} قيد" : ''));
            } catch (DomainException $e) {
                $failed++;
                $this->error("مالك #{$owner->id}: {$e->getMessage()}");
            }
        }
        $this->info(
            $reconcile
                ? "تمت محاذاة الاستحقاقات لـ {$count} مالك · صفوف معكوسة/مصحَّحة: {$changed}."
                : "تم ترحيل الاستحقاق حتى {$through} لـ {$count} مالك · صفوف جديدة/مصحَّحة: {$changed}."
        );

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
