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
                            {--through= : شهر YYYY-MM (افتراضي الشهر الحالي)}';

    protected $description = 'ترحيل استحقاق ملاك العربيات بشكل صريح ومُدقَّق (لا يُستدعى من GET)';

    public function handle(VehicleOwnerService $owners): int
    {
        $through = $this->option('through') ?: now()->format('Y-m');
        if (! preg_match('/^\d{4}-\d{2}$/', $through)) {
            $this->error('صيغة --through يجب أن تكون YYYY-MM');

            return self::FAILURE;
        }

        $query = VehicleOwner::query()->where('is_active', true)->orderBy('id');
        if ($id = $this->option('owner')) {
            $query->whereKey((int) $id);
        }

        $count = 0;
        $failed = 0;
        foreach ($query->cursor() as $owner) {
            try {
                $owners->ensureAccruedThrough($owner, $through);
                $count++;
                $this->line("· مالك #{$owner->id} {$owner->full_name}");
            } catch (DomainException $e) {
                $failed++;
                $this->error("مالك #{$owner->id}: {$e->getMessage()}");
            }
        }
        $this->info("تم ترحيل الاستحقاق حتى {$through} لـ {$count} مالك.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
