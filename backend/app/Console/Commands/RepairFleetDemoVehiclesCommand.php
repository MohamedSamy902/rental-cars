<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use App\Support\FleetCatalog;
use Illuminate\Console\Command;

/**
 * Fixes incoherent brand/model pairs left by older MegaSeeder runs
 * (e.g. MG + صني) so showroom share text matches real fleet names.
 */
class RepairFleetDemoVehiclesCommand extends Command
{
    protected $signature = 'fleet:repair-demo-vehicles
                            {--dry-run : عرض التعديلات بلا حفظ}
                            {--all-mismatched : أصلح أي عربية موديلها لا يخص ماركتها من الكتالوج}';

    protected $description = 'إصلاح ماركة/موديل غير متطابقين في بيانات البذرة (MG-*) والمعرض';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all-mismatched');

        $query = Vehicle::query()->orderBy('id');
        if (! $all) {
            $query->where(function ($q) {
                $q->where('code', 'like', 'MG-%')
                    ->orWhere('notes', 'like', '%بذرة ميجا%');
            });
        }

        $fixed = 0;
        $skipped = 0;
        foreach ($query->cursor() as $vehicle) {
            $brand = (string) $vehicle->brand;
            $model = (string) $vehicle->model;
            if (FleetCatalog::modelBelongsToBrand($brand, $model)) {
                $skipped++;
                continue;
            }

            $n = (int) preg_replace('/\D+/', '', (string) $vehicle->code) ?: (int) $vehicle->id;
            $pair = FleetCatalog::pairForIndex($n);
            $category = FleetCatalog::categoryForModel($pair['model']);

            $this->line(sprintf(
                '· #%d %s: %s %s → %s %s',
                $vehicle->id,
                $vehicle->code,
                $brand,
                $model,
                $pair['brand'],
                $pair['model'],
            ));

            if (! $dry) {
                $vehicle->update([
                    'brand' => $pair['brand'],
                    'model' => $pair['model'],
                    'category' => $category,
                ]);
            }
            $fixed++;
        }

        $this->info(sprintf(
            '%s%d صف%s · متوافقة مسبقاً: %d',
            $dry ? 'معاينة: ' : 'تم إصلاح ',
            $fixed,
            $fixed === 1 ? '' : 'وف',
            $skipped,
        ));

        return self::SUCCESS;
    }
}
