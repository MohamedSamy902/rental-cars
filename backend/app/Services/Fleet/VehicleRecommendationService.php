<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * درجة الظهور = (وزن قلة الاستخدام × درجته + وزن التوفر × درجته + وزن اليدوي × درجته) ÷ 100
 */
class VehicleRecommendationService
{
    public function __construct(private readonly VehicleAvailabilityService $availability) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rankAvailable(?string $category = null, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy() : now();
        $weights = $this->weights();

        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->when($category, fn ($q) => $q->where('category', $category))
            ->get();

        $activeIds = Contract::query()->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', $asOf)
            ->where('planned_ends_at', '>=', $asOf)
            ->pluck('vehicle_id')
            ->flip();

        $usageDays = $this->usageDaysLast90($vehicles->pluck('id'), $asOf);
        $maxUsage = max(1, (int) ($usageDays->max() ?: 0));

        $rows = [];
        foreach ($vehicles as $v) {
            $status = $this->availability->statusOnDate([
                'manual_status' => $v->manual_status,
                'has_active_handover' => $activeIds->has($v->id),
                'has_approved_covering_date' => $reservedIds->has($v->id),
                'open_work_order_blocks' => $v->manual_status === 'maintenance',
            ]);
            if ($status !== 'available') {
                continue;
            }

            $used = (int) ($usageDays[$v->id] ?? 0);
            $underuseScore = (int) round((1 - ($used / $maxUsage)) * 100);
            $availabilityScore = $this->availabilityStreakScore($v->id, $asOf);
            $manualScore = max(0, min(100, (int) $v->rental_priority));

            $display = (
                ($weights['underuse'] * $underuseScore)
                + ($weights['availability'] * $availabilityScore)
                + ($weights['manual'] * $manualScore)
            ) / 100;

            $rows[] = [
                'id' => $v->id,
                'brand' => $v->brand,
                'model' => $v->model,
                'plate' => $v->plate,
                'category' => $v->category,
                'daily_rate' => (int) $v->daily_rate,
                'default_deposit' => (int) $v->default_deposit,
                'odometer' => (int) $v->odometer,
                'rental_priority' => $manualScore,
                'scores' => [
                    'underuse' => $underuseScore,
                    'availability' => $availabilityScore,
                    'manual' => $manualScore,
                    'display' => round($display, 2),
                ],
                'weights' => $weights,
            ];
        }

        usort($rows, fn ($a, $b) => $b['scores']['display'] <=> $a['scores']['display']);

        return $rows;
    }

    /**
     * @return array{underuse:int,availability:int,manual:int}
     */
    public function weights(): array
    {
        $ops = TenantSetting::query()->value('operational') ?? [];
        $underuse = (int) ($ops['recommendation_underuse_weight'] ?? 40);
        $availability = (int) ($ops['recommendation_availability_weight'] ?? 30);
        $manual = (int) ($ops['recommendation_manual_weight'] ?? 30);
        $sum = max(1, $underuse + $availability + $manual);

        return [
            'underuse' => (int) round(($underuse / $sum) * 100),
            'availability' => (int) round(($availability / $sum) * 100),
            'manual' => (int) round(($manual / $sum) * 100),
        ];
    }

    /**
     * @param  Collection<int, int|string>  $vehicleIds
     * @return Collection<int, int>
     */
    private function usageDaysLast90(Collection $vehicleIds, Carbon $asOf): Collection
    {
        $from = $asOf->copy()->subDays(90)->startOfDay();
        $to = $asOf->copy()->endOfDay();
        $map = collect();
        foreach ($vehicleIds as $id) {
            $map[(int) $id] = 0;
        }

        $contracts = Contract::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereIn('status', ['active', 'completed'])
            ->where('starts_at', '<=', $to)
            ->where(function ($q) use ($from) {
                $q->whereNull('actual_ends_at')->where('planned_ends_at', '>=', $from)
                    ->orWhere('actual_ends_at', '>=', $from);
            })
            ->get(['vehicle_id', 'starts_at', 'planned_ends_at', 'actual_ends_at']);

        foreach ($contracts as $c) {
            $start = Carbon::parse($c->starts_at)->startOfDay()->max($from);
            $end = Carbon::parse($c->actual_ends_at ?: $c->planned_ends_at)->startOfDay()->min($to->copy()->startOfDay());
            if ($end->lt($start)) {
                continue;
            }
            $days = (int) $start->diffInDays($end) + 1;
            $vid = (int) $c->vehicle_id;
            $map[$vid] = (int) ($map[$vid] ?? 0) + $days;
        }

        return $map;
    }

    private function availabilityStreakScore(int $vehicleId, Carbon $asOf): int
    {
        $lastEnd = Contract::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', ['completed', 'active', 'approved'])
            ->orderByDesc('planned_ends_at')
            ->value('actual_ends_at')
            ?? Contract::query()
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', ['completed', 'active', 'approved'])
                ->orderByDesc('planned_ends_at')
                ->value('planned_ends_at');

        if (! $lastEnd) {
            return 100;
        }

        $days = max(0, (int) Carbon::parse($lastEnd)->startOfDay()->diffInDays($asOf->copy()->startOfDay()));
        // Cap streak contribution at 60 days → 100 score
        return (int) min(100, round(($days / 60) * 100));
    }
}
