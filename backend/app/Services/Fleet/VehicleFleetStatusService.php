<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Batch fleet status context — one query set for many vehicles (no N+1).
 */
class VehicleFleetStatusService
{
    public function __construct(
        private readonly VehicleAvailabilityService $availability,
    ) {}

    /**
     * @param  Collection<int, Vehicle>|iterable<Vehicle>|null  $vehicles
     * @return array{
     *     activeIds: Collection<int|string, int>,
     *     reservedIds: Collection<int|string, int>,
     *     blockingWoIds: Collection<int|string, int>,
     *     returningByVehicle: Collection<int, CarbonInterface|string|null>
     * }
     */
    public function buildContext(?iterable $vehicles = null): array
    {
        $ids = null;
        if ($vehicles !== null) {
            $ids = collect($vehicles)->map(fn (Vehicle $v) => (int) $v->id)->filter()->unique()->values()->all();
            if ($ids === []) {
                return [
                    'activeIds' => collect(),
                    'reservedIds' => collect(),
                    'blockingWoIds' => collect(),
                    'returningByVehicle' => collect(),
                ];
            }
        }

        $activeQ = Contract::query()->where('status', 'active');
        $reservedQ = Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('planned_ends_at', '>=', now());
        $woQ = WorkOrder::query()
            ->where('puts_in_maintenance', true)
            ->whereIn('status', ['open', 'in_progress']);

        if ($ids !== null) {
            $activeQ->whereIn('vehicle_id', $ids);
            $reservedQ->whereIn('vehicle_id', $ids);
            $woQ->whereIn('vehicle_id', $ids);
        }

        $activeIds = $activeQ->pluck('vehicle_id')->flip();
        $reservedIds = $reservedQ->pluck('vehicle_id')->flip();
        $blockingWoIds = $woQ->pluck('vehicle_id')->flip();

        $returningQ = Contract::query()
            ->whereIn('status', ['active', 'approved'])
            ->orderBy('planned_ends_at');
        if ($ids !== null) {
            $returningQ->whereIn('vehicle_id', $ids);
        }
        $returningByVehicle = $returningQ
            ->get(['vehicle_id', 'planned_ends_at'])
            ->groupBy('vehicle_id')
            ->map(fn (Collection $rows) => $rows->first()?->planned_ends_at);

        return [
            'activeIds' => $activeIds,
            'reservedIds' => $reservedIds,
            'blockingWoIds' => $blockingWoIds,
            'returningByVehicle' => $returningByVehicle,
        ];
    }

    /**
     * @param  array{
     *     activeIds: Collection<int|string, int>,
     *     reservedIds: Collection<int|string, int>,
     *     blockingWoIds: Collection<int|string, int>,
     *     returningByVehicle?: Collection<int, mixed>
     * }  $context
     */
    public function statusFor(Vehicle $vehicle, array $context): string
    {
        return $this->availability->statusOnDate([
            'manual_status' => $vehicle->manual_status,
            'has_active_handover' => $context['activeIds']->has($vehicle->id),
            'has_approved_covering_date' => $context['reservedIds']->has($vehicle->id),
            'open_work_order_blocks' => $vehicle->manual_status === 'maintenance'
                || $context['blockingWoIds']->has($vehicle->id),
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function summarizeActiveFleet(): array
    {
        $vehicles = Vehicle::query()->where('is_active', true)->get([
            'id', 'manual_status', 'tracker_sim_next_recharge_on', 'owner_agreement_ends_on',
        ]);
        $context = $this->buildContext($vehicles);
        $counts = [
            'total' => $vehicles->count(),
            'available' => 0,
            'rented' => 0,
            'reserved' => 0,
            'maintenance' => 0,
            'out_of_service' => 0,
            'license_expiring' => 0,
            'sim_recharge' => 0,
            'owner_agreement_expiring' => 0,
        ];

        foreach ($vehicles as $v) {
            $status = $this->statusFor($v, $context);
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        $licenseUntil = now()->addDays(30)->toDateString();
        $counts['license_expiring'] = Vehicle::query()
            ->where('is_active', true)
            ->whereHas('documents', function ($d) use ($licenseUntil) {
                $d->where(function ($qq) {
                    $qq->where('type', 'رخصة تسيير')
                        ->orWhere('type', 'like', '%رخص%')
                        ->orWhere('type', 'like', '%license%');
                })
                    ->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<=', $licenseUntil);
            })
            ->count();

        $simUntil = now()->addDays(14)->toDateString();
        $counts['sim_recharge'] = Vehicle::query()
            ->where('is_active', true)
            ->whereNotNull('tracker_sim_next_recharge_on')
            ->whereDate('tracker_sim_next_recharge_on', '<=', $simUntil)
            ->count();

        $ownerUntil = now()->addDays(30)->toDateString();
        $counts['owner_agreement_expiring'] = Vehicle::query()
            ->where('is_active', true)
            ->whereNotNull('owner_agreement_ends_on')
            ->whereDate('owner_agreement_ends_on', '<=', $ownerUntil)
            ->count();

        return $counts;
    }
}
