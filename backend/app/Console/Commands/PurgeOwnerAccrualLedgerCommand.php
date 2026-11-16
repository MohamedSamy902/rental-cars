<?php

namespace App\Console\Commands;

use App\Models\VehicleOwner;
use App\Services\Fleet\VehicleOwnerService;
use Illuminate\Console\Command;

class PurgeOwnerAccrualLedgerCommand extends Command
{
    protected $signature = 'owners:purge-accrual-ledger
                            {--owner= : معرف مالك واحد}
                            {--force : تنفيذ بدون تأكيد}';

    protected $description = 'تنظيف استحقاقات الملاك المتراكمة وإعادة تصنيف الصرفات القديمة إلى مصروف نقدي (يحفظ سجلات الصرف)';

    public function handle(VehicleOwnerService $owners): int
    {
        if (! $this->option('force') && ! $this->confirm('تنظيف دفتر استحقاق الملاك المتراكم؟ الصرفات النقدية تبقى.')) {
            $this->warn('أُلغي.');

            return self::SUCCESS;
        }

        $only = null;
        if ($id = $this->option('owner')) {
            $only = VehicleOwner::query()->find((int) $id);
            if (! $only) {
                $this->error("المالك #{$id} غير موجود.");

                return self::FAILURE;
            }
        }

        $result = $owners->purgeAccrualLedger($only, null);
        $this->info(sprintf(
            'تم التنظيف · مستحقات محذوفة: %d · صرفات أُعيد تصنيفها: %d',
            $result['dues_cleared'],
            $result['payouts_reclassed'],
        ));

        return self::SUCCESS;
    }
}
