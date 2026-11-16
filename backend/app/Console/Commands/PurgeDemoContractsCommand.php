<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Violation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * يحذف عقود DEMO-* التجريبية وما يتبعها دون المساس ببيانات التشغيل الحقيقية.
 */
class PurgeDemoContractsCommand extends Command
{
    protected $signature = 'rpm:purge-demo-contracts {--force : تنفيذ بدون تأكيد}';

    protected $description = 'حذف عقود DEMO-* التجريبية وقيودها/مدفوعاتها/مخالفاتها المرتبطة';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('حذف كل عقود DEMO-* المرتبطة؟', false)) {
            $this->warn('أُلغي.');

            return self::FAILURE;
        }

        $ids = Contract::withTrashed()
            ->where('number', 'like', 'DEMO-%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            $this->info('لا عقود DEMO للحذف.');

            return self::SUCCESS;
        }

        $deleted = DB::transaction(function () use ($ids) {
            Violation::query()->whereIn('contract_id', $ids)->delete();
            Payment::query()->where('link_type', 'contract')->whereIn('link_id', $ids)->delete();

            $sources = [];
            foreach ($ids as $id) {
                $sources[] = 'contract_close:'.$id;
                $sources[] = 'rent_recog:'.$id.'%';
            }
            $entryIds = JournalEntry::query()
                ->where(function ($q) use ($ids) {
                    foreach ($ids as $id) {
                        $q->orWhere('source', 'like', 'rent_recog:'.$id.':%')
                            ->orWhere('source', 'like', 'rent_recog_adj:'.$id.':%')
                            ->orWhere('source', 'contract_close:'.$id);
                    }
                })
                ->pluck('id');
            if ($entryIds->isNotEmpty()) {
                JournalLine::query()->whereIn('journal_entry_id', $entryIds)->delete();
                JournalEntry::query()->whereIn('id', $entryIds)->delete();
            }

            if (Schema::hasTable('inspections')) {
                DB::table('inspections')->whereIn('contract_id', $ids)->delete();
            }
            if (Schema::hasTable('deposits')) {
                DB::table('deposits')->whereIn('contract_id', $ids)->delete();
            }

            return (int) Contract::withTrashed()->whereIn('id', $ids)->forceDelete();
        });

        $this->info("حُذف {$deleted} عقد DEMO.");

        return self::SUCCESS;
    }
}
