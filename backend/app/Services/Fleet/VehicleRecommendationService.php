<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\Vehicle;
use Carbon\Carbon;

/**
 * Lists vehicles available for a booking window — no scoring / weights / visibility ranks.
 */
class VehicleRecommendationService
{
    public function __construct(private readonly VehicleAvailabilityService $availability) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rankAvailable(
        ?string $category = null,
        ?Carbon $asOf = null,
        ?Carbon $periodFrom = null,
        ?Carbon $periodTo = null,
    ): array {
        $asOf = $asOf ? $asOf->copy() : now();

        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->with(['accessories' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('brand')
            ->orderBy('model')
            ->orderBy('plate')
            ->get();

        $activeIds = Contract::query()->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', $asOf)
            ->where('planned_ends_at', '>=', $asOf)
            ->pluck('vehicle_id')
            ->flip();

        $busyInPeriod = collect();
        if ($periodFrom && $periodTo && $periodTo->gte($periodFrom)) {
            $busyInPeriod = Contract::query()
                ->whereIn('status', ['pending', 'approved', 'active'])
                ->where('starts_at', '<', $periodTo)
                ->where(function ($q) use ($periodFrom) {
                    $q->where(function ($qq) use ($periodFrom) {
                        $qq->whereNull('actual_ends_at')->where('planned_ends_at', '>', $periodFrom);
                    })->orWhere('actual_ends_at', '>', $periodFrom);
                })
                ->pluck('vehicle_id')
                ->flip();
        }

        $rows = [];
        foreach ($vehicles as $v) {
            if ($busyInPeriod->has($v->id)) {
                continue;
            }

            $status = $this->availability->statusOnDate([
                'manual_status' => $v->manual_status,
                'has_active_handover' => $activeIds->has($v->id),
                'has_approved_covering_date' => $reservedIds->has($v->id),
                'open_work_order_blocks' => $v->manual_status === 'maintenance',
            ]);
            if ($periodFrom && $periodTo) {
                if (in_array($status, ['maintenance', 'out_of_service', 'sold'], true)) {
                    continue;
                }
            } elseif ($status !== 'available') {
                continue;
            }

            $rows[] = [
                'id' => $v->id,
                'brand' => $v->brand,
                'model' => $v->model,
                'plate' => $v->plate,
                'category' => $v->category,
                'daily_rate' => (int) $v->daily_rate,
                'default_deposit' => (int) $v->default_deposit,
                'odometer' => (int) $v->odometer,
                'accessories' => $v->accessories->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'qty' => (int) ($a->default_qty ?? 1),
                ])->values()->all(),
            ];
        }

        return $rows;
    }
}
