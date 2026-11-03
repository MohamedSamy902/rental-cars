<?php

namespace App\Services\Fleet;

use App\Models\Contract;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * تجهيز العربيات اليومي — ملخص تسليم / استلام / صيانة
 * لليوم وغداً والأيام الثلاثة التالية (أفق ٥ أيام افتراضياً).
 */
class FleetDailyPrepService
{
    /**
     * @return array{
     *   generated_at: string,
     *   horizon_days: int,
     *   days: list<array{
     *     date: string,
     *     label: string,
     *     is_today: bool,
     *     is_tomorrow: bool,
     *     groups: array{
     *       pickups: list<array<string,mixed>>,
     *       returns: list<array<string,mixed>>,
     *       maintenance: list<array<string,mixed>>
     *     },
     *     counts: array{pickups:int,returns:int,maintenance:int,total:int}
     *   }>,
     *   totals: array{pickups:int,returns:int,maintenance:int,total:int}
     * }
     */
    public function summarize(?Carbon $from = null, int $horizonDays = 5): array
    {
        $from = ($from ? $from->copy() : now())->timezone(config('app.timezone', 'Africa/Cairo'))->startOfDay();
        $horizonDays = max(1, min(14, $horizonDays));
        $to = $from->copy()->addDays($horizonDays - 1)->endOfDay();

        $contracts = Contract::query()
            ->with([
                'customer:id,full_name,phone',
                'vehicle:id,brand,model,plate,code',
            ])
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('starts_at', [$from, $to])
                    ->orWhereBetween('planned_ends_at', [$from, $to]);
            })
            ->get();

        $workOrders = WorkOrder::query()
            ->with(['vehicle:id,brand,model,plate,code'])
            ->whereIn('status', ['open', 'in_progress'])
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('next_service_on', [$from->toDateString(), $to->toDateString()])
                    ->orWhere(function ($qq) use ($from, $to) {
                        $qq->whereNull('next_service_on')
                            ->whereBetween('opened_on', [$from->toDateString(), $to->toDateString()]);
                    });
            })
            ->limit(200)
            ->get();

        $days = [];
        $totals = ['pickups' => 0, 'returns' => 0, 'maintenance' => 0, 'total' => 0];
        $period = CarbonPeriod::create($from, $from->copy()->addDays($horizonDays - 1));

        foreach ($period as $day) {
            /** @var Carbon $day */
            $dateKey = $day->toDateString();
            $pickups = [];
            $returns = [];

            foreach ($contracts as $c) {
                if ($c->starts_at && $c->starts_at->toDateString() === $dateKey && in_array($c->status, ['pending', 'approved'], true)) {
                    $pickups[] = $this->presentContract($c, 'pickup');
                }
                if (
                    $c->status === 'active'
                    && $c->planned_ends_at
                    && $c->planned_ends_at->toDateString() === $dateKey
                ) {
                    $returns[] = $this->presentContract($c, 'return');
                }
            }

            $maintenance = [];
            foreach ($workOrders as $wo) {
                $serviceOn = $wo->next_service_on?->toDateString();
                $openedOn = $wo->opened_on?->toDateString();
                $bucketDate = $serviceOn ?: $openedOn;
                if ($bucketDate !== $dateKey) {
                    continue;
                }
                $maintenance[] = [
                    'id' => $wo->id,
                    'kind' => 'maintenance',
                    'title' => $wo->workshop ?: 'أمر شغل',
                    'status' => $wo->status,
                    'vehicle' => $wo->vehicle ? [
                        'id' => $wo->vehicle->id,
                        'label' => trim(($wo->vehicle->brand ?? '').' '.($wo->vehicle->model ?? '')),
                        'plate' => $wo->vehicle->plate,
                    ] : null,
                    'at' => $bucketDate,
                ];
            }

            $counts = [
                'pickups' => count($pickups),
                'returns' => count($returns),
                'maintenance' => count($maintenance),
                'total' => count($pickups) + count($returns) + count($maintenance),
            ];
            $totals['pickups'] += $counts['pickups'];
            $totals['returns'] += $counts['returns'];
            $totals['maintenance'] += $counts['maintenance'];
            $totals['total'] += $counts['total'];

            $days[] = [
                'date' => $dateKey,
                'label' => $this->dayLabel($day, $from),
                'is_today' => $day->isSameDay($from),
                'is_tomorrow' => $day->isSameDay($from->copy()->addDay()),
                'groups' => [
                    'pickups' => $pickups,
                    'returns' => $returns,
                    'maintenance' => $maintenance,
                ],
                'counts' => $counts,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'horizon_days' => $horizonDays,
            'days' => $days,
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentContract(Contract $c, string $kind): array
    {
        return [
            'id' => $c->id,
            'kind' => $kind,
            'contract_id' => $c->id,
            'number' => $c->number,
            'status' => $c->status,
            'customer' => $c->customer?->full_name,
            'customer_phone' => $c->customer?->phone,
            'vehicle' => $c->vehicle ? [
                'id' => $c->vehicle->id,
                'label' => trim(($c->vehicle->brand ?? '').' '.($c->vehicle->model ?? '')),
                'plate' => $c->vehicle->plate,
            ] : null,
            'at' => ($kind === 'pickup' ? $c->starts_at : $c->planned_ends_at)?->toIso8601String(),
        ];
    }

    private function dayLabel(Carbon $day, Carbon $today): string
    {
        if ($day->isSameDay($today)) {
            return 'اليوم';
        }
        if ($day->isSameDay($today->copy()->addDay())) {
            return 'غداً';
        }

        return $day->locale('ar')->translatedFormat('D j M');
    }
}
