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
    public function monthOverMonth(?Carbon $anchor = null): array
    {
        $anchor = ($anchor ?? now())->copy()->startOfMonth();
        $current = $this->monthSnapshot($anchor);
        $previous = $this->monthSnapshot($anchor->copy()->subMonth());

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
     * @return array{label:string,revenue:int,maintenance:int,expenses:int,operating_profit:int,vehicles:array<int,array<string,mixed>>}
     */
    public function monthSnapshot(Carbon $monthStart): array
    {
        $year = (int) $monthStart->format('Y');
        $month = (int) $monthStart->format('n');
        $from = $monthStart->copy()->startOfMonth();
        $to = $monthStart->copy()->endOfMonth();

        $revenue = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->whereIn('code', ['4000', '4100', '4200', '4300']))
            ->whereHas('entry', fn ($q) => $q->whereYear('entry_date', $year)->whereMonth('entry_date', $month))
            ->sum('credit');

        // Fallback: completed/active period rent for the month if journals empty
        if ($revenue === 0) {
            $revenue = (int) Contract::query()
                ->whereIn('status', ['completed', 'active'])
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('actual_ends_at', [$from, $to])
                        ->orWhere(function ($qq) use ($from, $to) {
                            $qq->whereNull('actual_ends_at')
                                ->where('starts_at', '<=', $to)
                                ->where('planned_ends_at', '>=', $from);
                        });
                })
                ->sum('period_rent');
        }

        $maintenance = (int) WorkOrder::query()
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('opened_on', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('closed_on', [$from->toDateString(), $to->toDateString()]);
            })
            ->get(['parts_cost', 'labor_cost'])
            ->sum(fn ($w) => (int) $w->parts_cost + (int) $w->labor_cost);

        $expenses = (int) Expense::query()
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $vehicles = [];
        foreach (Vehicle::query()->where('is_active', true)->get(['id', 'brand', 'model', 'plate']) as $v) {
            $vRev = (int) Contract::query()
                ->where('vehicle_id', $v->id)
                ->whereIn('status', ['completed', 'active'])
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('actual_ends_at', [$from, $to])
                        ->orWhere(function ($qq) use ($from, $to) {
                            $qq->whereNull('actual_ends_at')
                                ->where('starts_at', '<=', $to)
                                ->where('planned_ends_at', '>=', $from);
                        });
                })
                ->sum('period_rent');
            $vMaint = (int) WorkOrder::query()
                ->where('vehicle_id', $v->id)
                ->where(function ($q) use ($from, $to) {
                    $q->whereBetween('opened_on', [$from->toDateString(), $to->toDateString()])
                        ->orWhereBetween('closed_on', [$from->toDateString(), $to->toDateString()]);
                })
                ->get(['parts_cost', 'labor_cost'])
                ->sum(fn ($w) => (int) $w->parts_cost + (int) $w->labor_cost);
            $vExp = (int) Expense::query()
                ->where('vehicle_id', $v->id)
                ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
                ->sum('amount');
            $vehicles[$v->id] = [
                'vehicle_id' => $v->id,
                'label' => trim("{$v->brand} {$v->model} · {$v->plate}"),
                'revenue' => $vRev,
                'maintenance' => $vMaint,
                'expenses' => $vExp,
                'operating_profit' => $vRev - $vMaint - $vExp,
            ];
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
