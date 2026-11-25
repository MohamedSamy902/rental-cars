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
use App\Services\Contracts\RentalProgressService;
use App\Services\Dashboard\HomeDailyCashService;
use App\Services\Dashboard\HomeImportantTasksService;
use App\Services\Fleet\FleetDailyPrepService;
use App\Services\Fleet\OccupancyService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Support\PerfReadCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly VehicleAvailabilityService $availability,
        private readonly OccupancyService $occupancy,
        private readonly ProfitabilityService $profitability,
        private readonly RentalProgressService $progress,
        private readonly FleetDailyPrepService $dailyPrep,
        private readonly HomeImportantTasksService $importantTasks,
        private readonly HomeDailyCashService $dailyCash,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);

        $payload = PerfReadCache::remember("dashboard:{$userId}", function () use ($request, $user) {
            return $this->buildPayload($request, $user);
        });

        return response()->json($payload);
    }

    private function buildPayload(Request $request, $user): array
    {
        $canFinance = (bool) ($user?->hasPermission('reports.view') || $user?->hasPermission('accounting.view'));
        $canContracts = (bool) $user?->hasPermission('contracts.view');
        $canCustomers = (bool) $user?->hasPermission('customers.view');

        // حمولة مشتركة للأسطول/الصيانة — تُعاد استخدامها في الإشغال والربحية والمهام والتجهيز.
        $vehicles = Vehicle::query()->where('is_active', true)->get(['id', 'manual_status', 'brand', 'model', 'plate']);
        $now = now();
        $coverage = Contract::query()
            ->where(function ($q) use ($now) {
                $q->where('status', 'active')
                    ->orWhere(function ($qq) use ($now) {
                        $qq->where('status', 'approved')
                            ->where('starts_at', '<=', $now)
                            ->where('planned_ends_at', '>=', $now);
                    });
            })
            ->get(['vehicle_id', 'status', 'planned_ends_at']);
        $activeIds = $coverage->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = $coverage->where('status', 'approved')->pluck('vehicle_id')->flip();
        $returningToday = $canContracts
            ? $coverage->where('status', 'active')
                ->filter(fn (Contract $c) => $c->planned_ends_at && $c->planned_ends_at->isToday())
                ->count()
            : null;

        $openWorkOrders = WorkOrder::query()
            ->with(['vehicle:id,plate,brand,model,code'])
            ->whereIn('status', ['open', 'in_progress'])
            ->orderBy('opened_on')
            ->get();
        $blockingWo = $openWorkOrders
            ->filter(fn (WorkOrder $w) => (bool) $w->puts_in_maintenance)
            ->pluck('vehicle_id')
            ->flip();

        $statusCounts = [
            'available' => 0,
            'reserved' => 0,
            'rented' => 0,
            'maintenance' => 0,
            'out_of_service' => 0,
        ];
        foreach ($vehicles as $v) {
            $status = $this->availability->statusOnDate([
                'manual_status' => $v->manual_status,
                'has_active_handover' => $activeIds->has($v->id),
                'has_approved_covering_date' => $reservedIds->has($v->id),
                'open_work_order_blocks' => $v->manual_status === 'maintenance' || $blockingWo->has($v->id),
            ]);
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
            }
        }

        $active = $activeIds->count();
        $expectedRevenue = (int) Contract::query()
            ->whereIn('status', ['approved', 'active'])
            ->get(['frozen_daily_rate', 'starts_at', 'planned_ends_at'])
            ->sum(function (Contract $c) {
                return (int) $c->frozen_daily_rate * max(1, $c->starts_at->diffInDays($c->planned_ends_at));
            });

        $taskWith = $canCustomers
            ? ['customer:id,full_name', 'vehicle:id,brand,model']
            : ['vehicle:id,brand,model'];
        $taskRows = $canContracts
            ? Contract::query()
                ->with($taskWith)
                ->whereIn('status', ['pending', 'approved', 'active'])
                ->orderBy('planned_ends_at')
                ->limit(8)
                ->get()
            : collect();
        $tasks = $taskRows->map(function (Contract $c) use ($canCustomers) {
            $label = match ($c->status) {
                'pending' => 'اعتماد حجز معلّق',
                'approved' => 'تسليم',
                'active' => 'استلام',
                default => $c->status,
            };
            $vehicleLabel = trim(($c->vehicle?->brand ?? '').' '.($c->vehicle?->model ?? ''));
            $subtitle = $canCustomers
                ? trim(($c->customer?->full_name ?? '').' · '.$vehicleLabel)
                : $vehicleLabel;

            return [
                'id' => $c->id,
                'title' => "{$label} {$c->number}",
                'subtitle' => $subtitle,
                'status' => $c->status,
                'at' => $c->status === 'active' ? $c->planned_ends_at : $c->starts_at,
            ];
        });

        // KPI يعدّ كل الأصناف (سلوك سابق) · مهام المهمّة تقتصر على النشط كما كان.
        $inventoryRows = InventoryItem::query()
            ->withSum('batches as remaining_sum', 'remaining')
            ->orderBy('name')
            ->get();
        $lowStock = $inventoryRows
            ->filter(fn (InventoryItem $item) => (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->count();
        $lowStockItems = $inventoryRows
            ->filter(fn (InventoryItem $item) => (bool) $item->is_active
                && (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->take(6)
            ->values();

        $center = TenantSetting::query()->value('center_name') ?: 'شركة التأجير';
        $occupancyPct = $this->occupancy->percent(now()->startOfMonth(), now(), $vehicles->count());

        $openViolations = Violation::query()
            ->with('vehicle:id,plate')
            ->whereNotIn('status', ['paid', 'confirmed'])
            ->orderByDesc('occurred_at')
            ->get();

        $importantTasks = $user
            ? $this->importantTasks->forUser($user, [
                'open_work_orders' => $openWorkOrders,
                'low_stock_items' => $lowStockItems,
                'open_violations' => $openViolations,
            ])
            : [];

        return [
            'center_name' => $center,
            'occupancy_pct' => (int) round($occupancyPct),
            'occupancy_pct_precise' => $occupancyPct,
            'fleet_total' => $vehicles->count(),
            'fleet_status' => $statusCounts,
            'profitability_compare' => $canFinance
                ? $this->profitability->monthOverMonth(null, $vehicles)
                : null,
            'kpis' => [
                'available' => $statusCounts['available'],
                'rented' => $statusCounts['rented'],
                'returning_today' => $returningToday,
                'expected_revenue' => $canFinance ? $expectedRevenue : null,
                'customers' => $canCustomers ? Customer::query()->count() : null,
                'active_contracts' => $canContracts ? $active : null,
                'open_work_orders' => $openWorkOrders->count(),
                'open_violations' => $openViolations->count(),
                'low_stock' => $lowStock,
                'unread_notifications' => Notification::query()
                    ->when($user, fn ($q) => $q->where('user_id', $user->id))
                    ->whereNull('read_at')
                    ->count(),
            ],
            'tasks' => $canContracts ? $tasks : [],
            'important_tasks' => $importantTasks,
            'fleet_prep' => $this->dailyPrep->summarize(now(), 5, $canContracts, $canCustomers, $openWorkOrders),
            'daily_cash' => $canFinance ? $this->dailyCash->forUser($user) : null,
            'recent_contracts' => $canContracts ? (function () use ($canCustomers) {
                $recent = Contract::query()
                    ->with($canCustomers
                        ? ['customer:id,full_name', 'vehicle:id,brand,model,plate']
                        : ['vehicle:id,brand,model,plate'])
                    ->latest('id')
                    ->limit(5)
                    ->get();
                $openMaint = $this->progress->openMaintenanceByVehicleIds($recent->pluck('vehicle_id')->all());

                return $recent->map(function (Contract $c) use ($canCustomers, $openMaint) {
                    $arr = $c->toArray();
                    if (! $canCustomers) {
                        $arr['customer'] = null;
                    }
                    $arr['progress'] = $this->progress->forContract($c, null, $openMaint);

                    return $arr;
                })->values();
            })() : [],
        ];
    }
}
