<?php

namespace App\Console\Commands;

use App\Services\Accounting\ProfitabilitySnapshotService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RebuildProfitMonthSummariesCommand extends Command
{
    protected $signature = 'profitability:rebuild-month-summaries
        {--month= : YYYY-MM (افتراضي: الشهر الحالي) }
        {--previous : أعد بناء الشهر السابق أيضاً}
        {--assert : اخرج بفشل إن اختلف الملخص عن التجميع المباشر}';

    protected $description = 'BE-8: إعادة بناء ملخص ربحية شهري مشتق (لا يمسّ صفوف الأعمال)';

    public function handle(ProfitabilitySnapshotService $snapshots): int
    {
        $monthOpt = $this->option('month');
        $anchor = $monthOpt
            ? Carbon::createFromFormat('Y-m', (string) $monthOpt)->startOfMonth()
            : now()->startOfMonth();

        $months = [$anchor];
        if ($this->option('previous')) {
            $months[] = $anchor->copy()->subMonth()->startOfMonth();
        }

        foreach ($months as $m) {
            $result = $snapshots->rebuildMonth($m);
            $this->info(sprintf(
                '%s → %d صف · ربح تشغيلي أسطول=%d',
                $m->format('Y-m'),
                $result['rows'],
                $result['fleet_operating_profit']
            ));

            if ($this->option('assert')) {
                $diffs = $snapshots->diffAgainstLive($m);
                if ($diffs !== []) {
                    $this->error('تكافؤ فاشل: '.implode('; ', $diffs));

                    return self::FAILURE;
                }
                $this->info('تكافؤ 100٪ مع التجميع المباشر.');
            }
        }

        return self::SUCCESS;
    }
}
