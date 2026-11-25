<?php

namespace App\Services\Accounting;

use App\Models\Contract;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Carbon\Carbon;

class ProfitabilityService
{
    /**
     * ربحية التشغيل = إيراد معترف به − صيانة − مصروفات السيارة
     *
     * @return array<string, mixed>
     */
    /**
     * @param  \Illuminate\Support\Collection<int, Vehicle>|null  $activeVehicles
     */
    public function monthOverMonth(?Carbon $anchor = null, $activeVehicles = null): array
    {
        $anchor = ($anchor ?? now())->copy()->startOfMonth();
        $activeVehicles = $activeVehicles ?? Vehicle::query()->where('is_active', true)->get(['id', 'brand', 'model', 'plate']);
        $current = $this->monthSnapshot($anchor, $activeVehicles);
        $previous = $this->monthSnapshot($anchor->copy()->subMonth(), $activeVehicles);

        $delta = $current['operating_profit'] - $previous['operating_profit'];
        $deltaPct = $previous['operating_profit'] == 0
            ? ($current['operating_profit'] > 0 ? 100.0 : 0.0)
            : round(($delta / abs($previous['operating_profit'])) * 100, 2);

        $byVehicle = [];
        $vehicleIds = collect($current['vehicles'])->keys()
            ->merge(collect($previous['vehicles'])->keys())
            ->unique()
            ->values();
        foreach ($vehicleIds as $vid) {
            $cur = $current['vehicles'][$vid] ?? $this->emptyVehicleRow((int) $vid);
            $prev = $previous['vehicles'][$vid] ?? $this->emptyVehicleRow((int) $vid);
            $d = $cur['operating_profit'] - $prev['operating_profit'];
            $dp = $prev['operating_profit'] == 0
                ? ($cur['operating_profit'] > 0 ? 100.0 : 0.0)
                : round(($d / abs($prev['operating_profit'])) * 100, 2);
            $byVehicle[] = [
                'vehicle_id' => (int) $vid,
                'label' => $cur['label'] ?? $prev['label'],
                'current' => $cur,
                'previous' => $prev,
                'delta' => $d,
                'delta_pct' => $dp,
            ];
        }
        usort($byVehicle, fn ($a, $b) => $b['current']['operating_profit'] <=> $a['current']['operating_profit']);

        return [
            'current_month' => $current['label'],
            'previous_month' => $previous['label'],
            'fleet' => [
                'current' => [
                    'revenue' => $current['revenue'],
                    'maintenance' => $current['maintenance'],
                    'expenses' => $current['expenses'],
                    'operating_profit' => $current['operating_profit'],
                ],
                'previous' => [
                    'revenue' => $previous['revenue'],
                    'maintenance' => $previous['maintenance'],
                    'expenses' => $previous['expenses'],
                    'operating_profit' => $previous['operating_profit'],
                ],
                'delta' => $delta,
                'delta_pct' => $deltaPct,
            ],
            'vehicles' => $byVehicle,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Vehicle>|null  $activeVehicles
     * @return array{label:string,revenue:int,maintenance:int,expenses:int,operating_profit:int,vehicles:array<int,array<string,mixed>>}
     */
    public function monthSnapshot(Carbon $monthStart, $activeVehicles = null): array
    {
        // BE-8: استخدم الملخص المشتق إن وُجد (يُبطل عند الكتابة · يُعاد بناؤه عبر Cron/أمر).
        if ((bool) config('rpm.use_profit_month_summaries', true)) {
            $fromSnap = app(ProfitabilitySnapshotService::class)->tryMonthSnapshot($monthStart);
            if ($fromSnap !== null) {
                return $fromSnap;
            }
        }

        return $this->computeMonthSnapshotLive($monthStart, $activeVehicles);
    }

    /**
     * التجميع المباشر — مصدر الحقيقة لإعادة بناء الملخص المشتق واختبارات التكافؤ.
     *
     * @param  \Illuminate\Support\Collection<int, Vehicle>|null  $activeVehicles
     * @return array{label:string,revenue:int,maintenance:int,expenses:int,operating_profit:int,vehicles:array<int,array<string,mixed>>}
     */
    public function computeMonthSnapshotLive(Carbon $monthStart, $activeVehicles = null): array
    {
        $year = (int) $monthStart->format('Y');
        $month = (int) $monthStart->format('n');
        $from = $monthStart->copy()->startOfMonth();
        $to = $monthStart->copy()->endOfMonth();

        $journalCredits = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('accounts.code', ['4000', '4100', '4200', '4300', '4400'])
            ->whereYear('journal_entries.entry_date', $year)
            ->whereMonth('journal_entries.entry_date', $month)
            ->whereNull('journal_lines.deleted_at')
            ->whereNull('journal_entries.deleted_at')
            ->get(['journal_lines.vehicle_id', 'journal_lines.credit']);

        $useJournals = $journalCredits->isNotEmpty();
        $revenueByVehicle = [];
        $revenue = 0;
        if ($useJournals) {
            foreach ($journalCredits as $line) {
                $vid = (int) ($line->vehicle_id ?? 0);
                $revenueByVehicle[$vid] = ($revenueByVehicle[$vid] ?? 0) + (int) $line->credit;
            }
            $revenue = (int) array_sum($revenueByVehicle);
        }

        $workOrders = WorkOrder::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('opened_on', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('closed_on', [$from->toDateString(), $to->toDateString()]);
            })
            ->get(['vehicle_id', 'parts_cost', 'labor_cost']);

        $maintenance = (int) $workOrders->sum(fn ($w) => (int) $w->parts_cost + (int) $w->labor_cost);
        $maintenanceByVehicle = [];
        foreach ($workOrders as $w) {
            $vid = (int) $w->vehicle_id;
            $maintenanceByVehicle[$vid] = ($maintenanceByVehicle[$vid] ?? 0)
                + (int) $w->parts_cost
                + (int) $w->labor_cost;
        }

        $expenseRows = Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->get(['vehicle_id', 'amount']);
        $expenses = (int) $expenseRows->sum(fn ($e) => (int) $e->amount);
        $expensesByVehicle = [];
        foreach ($expenseRows as $e) {
            if ($e->vehicle_id === null) {
                continue;
            }
            $vid = (int) $e->vehicle_id;
            $expensesByVehicle[$vid] = ($expensesByVehicle[$vid] ?? 0) + (int) $e->amount;
        }

        $contractRevByVehicle = [];
        if (! $useJournals) {
            $contractRevByVehicle = Contract::query()
                ->selectRaw('vehicle_id, SUM(period_rent) as aggregate')
                ->whereIn('status', ['completed', 'active'])
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('actual_ends_at', [$from, $to])
                        ->orWhere(function ($qq) use ($from, $to) {
                            $qq->whereNull('actual_ends_at')
                                ->where('starts_at', '<=', $to)
                                ->where('planned_ends_at', '>=', $from);
                        });
                })
                ->groupBy('vehicle_id')
                ->pluck('aggregate', 'vehicle_id')
                ->map(fn ($v) => (int) $v)
                ->all();
            $revenue = (int) array_sum($contractRevByVehicle);
        }

        $fleetVehicles = $activeVehicles ?? Vehicle::query()->where('is_active', true)->get(['id', 'brand', 'model', 'plate']);
        $vehicles = [];
        foreach ($fleetVehicles as $v) {
            if ($useJournals) {
                $vRev = (int) ($revenueByVehicle[$v->id] ?? 0);
            } else {
                $vRev = (int) ($contractRevByVehicle[$v->id] ?? 0);
            }
            $vMaint = (int) ($maintenanceByVehicle[$v->id] ?? 0);
            $vExp = (int) ($expensesByVehicle[$v->id] ?? 0);
            $vehicles[$v->id] = [
                'vehicle_id' => $v->id,
                'label' => trim("{$v->brand} {$v->model} · {$v->plate}"),
                'revenue' => $vRev,
                'maintenance' => $vMaint,
                'expenses' => $vExp,
                'operating_profit' => $vRev - $vMaint - $vExp,
            ];
        }

        // Fleet revenue must equal the sum of included vehicle rows (same recognition basis).
        if ($useJournals) {
            $allocated = (int) collect($vehicles)->sum('revenue');
            $journalTotal = (int) $journalCredits->sum('credit');
            // Rare orphan lines without vehicle_id: keep them in fleet only via a synthetic bucket.
            if ($journalTotal > $allocated) {
                $orphan = $journalTotal - $allocated;
                $vehicles[0] = [
                    'vehicle_id' => 0,
                    'label' => 'إيراد غير موزع على عربية',
                    'revenue' => $orphan,
                    'maintenance' => 0,
                    'expenses' => 0,
                    'operating_profit' => $orphan,
                ];
                $allocated = $journalTotal;
            }
            $revenue = $allocated;
        }

        return [
            'label' => $monthStart->translatedFormat('F Y'),
            'year' => $year,
            'month' => $month,
            'revenue' => $revenue,
            'maintenance' => $maintenance,
            'expenses' => $expenses,
            'operating_profit' => $revenue - $maintenance - $expenses,
            'vehicles' => $vehicles,
        ];
    }

    /**
     * @return array{vehicle_id:int,label:string,revenue:int,maintenance:int,expenses:int,operating_profit:int}
     */
    private function emptyVehicleRow(int $id): array
    {
        $v = Vehicle::query()->find($id);

        return [
            'vehicle_id' => $id,
            'label' => $v ? trim("{$v->brand} {$v->model} · {$v->plate}") : "#{$id}",
            'revenue' => 0,
            'maintenance' => 0,
            'expenses' => 0,
            'operating_profit' => 0,
        ];
    }
}
