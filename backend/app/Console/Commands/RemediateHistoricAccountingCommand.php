<?php

namespace App\Console\Commands;

use App\Services\Accounting\HistoricAccountingRemediationService;
use Illuminate\Console\Command;

class RemediateHistoricAccountingCommand extends Command
{
    protected $signature = 'accounting:remediate-historic';

    protected $description = 'تصحيح قيود الدفعات/الإيجار/FIFO التاريخية بشكل idempotent (QA historic heal)';

    public function handle(HistoricAccountingRemediationService $remediation): int
    {
        $result = $remediation->run();
        $this->info('تم تشغيل تصحيح القيود التاريخية:');
        foreach ($result as $key => $count) {
            $this->line("  · {$key}: {$count}");
        }

        return self::SUCCESS;
    }
}
