<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * نسبة الإشغال = (مجموع أيام التأجير في الفترة ÷ (سيارات نشطة × أيام الفترة)) × 100
 */
class OccupancyService
{
    public function percent(?CarbonInterface $from = null, ?CarbonInterface $to = null): float
    {
        $from = Carbon::parse($from ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($to ?? now())->endOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $periodDays = max(1, (int) $from->diffInDays($to->copy()->startOfDay()) + 1);
        $activeVehicles = Vehicle::query()->where('is_active', true)->count();
        if ($activeVehicles < 1) {
            return 0.0;
        }

        $rentedDays = $this->rentedDaysInPeriod($from, $to);
        $capacity = $activeVehicles * $periodDays;

        return round(($rentedDays / max(1, $capacity)) * 100, 2);
    }

    public function rentedDaysInPeriod(CarbonInterface $from, CarbonInterface $to): float
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        $contracts = Contract::query()
            ->whereIn('status', ['active', 'completed', 'approved'])
            ->where('starts_at', '<=', $to->copy()->endOfDay())
            ->where(function ($q) use ($from) {
                $q->where(function ($qq) use ($from) {
                    $qq->whereNull('actual_ends_at')->where('planned_ends_at', '>=', $from);
                })->orWhere('actual_ends_at', '>=', $from);
            })
            ->get(['starts_at', 'planned_ends_at', 'actual_ends_at', 'status']);

        $days = 0.0;
        foreach ($contracts as $c) {
            if ($c->status === 'approved' && Carbon::parse($c->starts_at)->startOfDay()->gt($to)) {
                continue;
            }
            $start = Carbon::parse($c->starts_at)->startOfDay()->max($from);
            $end = Carbon::parse($c->actual_ends_at ?: $c->planned_ends_at)->startOfDay()->min($to);
            if ($end->lt($start)) {
                continue;
            }
            $days += (int) $start->diffInDays($end) + 1;
        }

        return $days;
    }
}
