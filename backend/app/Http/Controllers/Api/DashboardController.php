<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Accounting\ProfitabilityService;
use App\Services\Contracts\ContractPricingService;
use App\Services\Contracts\RentalProgressService;
use App\Services\Dashboard\DashboardRevenueService;
use App\Services\Dashboard\HomeImportantTasksService;
use App\Services\Fleet\FleetDailyPrepService;
use App\Services\Fleet\OccupancyService;
use App\Services\Fleet\VehicleFleetStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly VehicleFleetStatusService $fleetStatus,
        private readonly OccupancyService $occupancy,
        private readonly ProfitabilityService $profitability,
        private readonly RentalProgressService $progress,
        private readonly FleetDailyPrepService $dailyPrep,
        private readonly HomeImportantTasksService $importantTasks,
        private readonly ContractPricingService $pricing,
        private readonly DashboardRevenueService $revenue,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $vehicles = Vehicle::query()->where('is_active', true)->get(['id', 'manual_status']);
        $context = $this->fleetStatus->buildContext($vehicles);

        $statusCounts = [
            'available' => 0,
            'reserved' => 0,
            'rented' => 0,
            'maintenance' => 0,
            'out_of_service' => 0,
        ];

        foreach ($vehicles as $v) {
            $status = $this->fleetStatus->statusFor($v, $context);
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $active = $context['activeIds']->count();
        $returningToday = Contract::query()
            ->where('status', 'active')
            ->whereDate('planned_ends_at', today())
            ->count();
        $expectedRevenue = (int) Contract::query()
            ->whereIn('status', ['approved', 'active'])
            ->get(['frozen_daily_rate', 'starts_at', 'planned_ends_at', 'approved_days', 'discount', 'other_fees'])
            ->sum(function (Contract $c) {
                return $this->pricing->estimatedPeriodRent(
                    (int) $c->frozen_daily_rate,
                    $c->starts_at,
                    $c->planned_ends_at,
                    $c->approved_days !== null ? (int) $c->approved_days : null,
                    null,
                    (int) $c->discount,
                    (int) $c->other_fees,
                );
            });

        $revenueRange = $request->string('revenue_range', 'week')->toString();
        if (! in_array($revenueRange, ['week', 'month', 'year'], true)) {
            $revenueRange = 'week';
        }

        $tasks = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,brand,model'])
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->orderBy('planned_ends_at')
            ->limit(8)
            ->get()
            ->map(function (Contract $c) {
                $label = match ($c->status) {
                    'pending' => 'اعتماد حجز معلّق',
                    'approved' => 'تسليم',
                    'active' => 'استلام',
                    default => $c->status,
                };

                return [
                    'id' => $c->id,
                    'title' => "{$label} {$c->number}",
                    'subtitle' => trim(($c->customer?->full_name ?? '').' · '.($c->vehicle?->brand ?? '').' '.($c->vehicle?->model ?? '')),
                    'status' => $c->status,
                    'at' => $c->status === 'active' ? $c->planned_ends_at : $c->starts_at,
                ];
            });

        $lowStock = InventoryItem::query()->withSum('batches as remaining_sum', 'remaining')->get()
            ->filter(fn (InventoryItem $item) => (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->count();

        $center = TenantSetting::query()->value('center_name') ?: 'مركز التأجير';

        $occupancyPct = $this->occupancy->percent(now()->startOfMonth(), now());

        return response()->json([
            'center_name' => $center,
            'occupancy_pct' => (int) round($occupancyPct),
            'occupancy_pct_precise' => $occupancyPct,
            'fleet_total' => $vehicles->count(),
            'fleet_status' => $statusCounts,
            'profitability_compare' => $this->profitability->monthOverMonth(),
            'kpis' => [
                'available' => $statusCounts['available'],
                'rented' => $statusCounts['rented'],
                'returning_today' => $returningToday,
                'expected_revenue' => $expectedRevenue,
                'customers' => Customer::query()->count(),
                'active_contracts' => $active,
                'open_work_orders' => WorkOrder::query()->whereIn('status', ['open', 'in_progress'])->count(),
                'open_violations' => Violation::query()->whereNotIn('status', ['paid', 'confirmed'])->count(),
                'low_stock' => $lowStock,
                'unread_notifications' => Notification::query()
                    ->when($request->user(), fn ($q) => $q->where('user_id', $request->user()->id))
                    ->whereNull('read_at')
                    ->count(),
            ],
            'tasks' => $tasks,
            'important_tasks' => $request->user()
                ? $this->importantTasks->forUser($request->user())
                : [],
            'fleet_prep' => $this->dailyPrep->summarize(now(), 5),
            'recent_contracts' => Contract::query()
                ->with(['customer:id,full_name', 'vehicle:id,brand,model,plate'])
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(function (Contract $c) {
                    $arr = $c->toArray();
                    $arr['progress'] = $this->progress->forContract($c);

                    return $arr;
                })
                ->values(),
            'revenue_series' => $this->revenue->series($revenueRange),
            'revenue_range' => $revenueRange,
        ]);
    }
}
