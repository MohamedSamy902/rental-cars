<?php

namespace App\Console\Commands;

use App\Services\Accounting\AccountingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Safety-net sync for open months (handover/return already write rent_recog).
 * Read paths never mutate journals — this command is the scheduled writer.
 */
class SyncRentRecognitionCommand extends Command
{
    protected $signature = 'accounting:sync-rent-recognition
        {--months=18 : How many past months (plus current and future horizon) to walk}
        {--dry-run : List months without posting}';

    protected $description = 'إثبات إيجار للشهور المفتوحة (idempotent) — لا يُستدعى من مسارات القراءة';

    public function handle(AccountingService $accounting): int
    {
        $tz = config('app.timezone');
        $lookback = max(1, (int) $this->option('months'));
        $keys = $accounting->overviewMonthKeys(CarbonImmutable::now($tz));

        // Cap to recent lookback from current month while keeping any future keys from overview.
        $today = CarbonImmutable::now($tz)->startOfMonth();
        $floor = $today->subMonths($lookback - 1);
        $keys = array_values(array_filter(
            $keys,
            function (array $pair) use ($floor) {
                [$y, $m] = $pair;
                $dt = CarbonImmutable::create($y, $m, 1);

                return $dt->greaterThanOrEqualTo($floor);
            }
        ));

        if ($this->option('dry-run')) {
            foreach ($keys as [$y, $m]) {
                $this->line(sprintf('%04d-%02d', $y, $m));
            }
            $this->info(count($keys).' شهراً (dry-run).');

            return self::SUCCESS;
        }

        $before = \App\Models\JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();
        $accounting->syncRentRecognition($keys);
        $after = \App\Models\JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();

        $this->info(sprintf(
            'مزامنة %d شهراً · rent_recog قبل=%d بعد=%d (Δ=%d)',
            count($keys),
            $before,
            $after,
            $after - $before
        ));

        return self::SUCCESS;
    }
}
