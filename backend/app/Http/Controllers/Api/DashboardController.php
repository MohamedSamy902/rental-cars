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

        // أسطول واحد غني يكفي الحالة + مهام الشريحة/عقد المالك (بلا استعلامات لاحقة).
        $vehicles = Vehicle::query()->where('is_active', true)->get([
            'id', 'manual_status', 'brand', 'model', 'plate', 'code', 'owner_id',
            'tracker_sim_number', 'tracker_sim_next_recharge_on', 'tracker_sim_last_recharged_on',
            'owner_agreement_starts_on', 'owner_agreement_ends_on',
        ]);
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
        // تحتاج استلام: عقود نشطة انتهى موعدها المخطط اليوم أو قبله (يشمل متأخر أمس…).
        $endOfToday = now()->endOfDay();
        $returningToday = $canContracts
            ? $coverage->where('status', 'active')
                ->filter(fn (Contract $c) => $c->planned_ends_at && $c->planned_ends_at->lte($endOfToday))
                ->count()
            : null;

        $openWorkOrders = WorkOrder::query()
            ->with(['vehicle:id,plate,brand,model,code'])
            ->whereIn('status', ['open', 'in_progress'])
            ->orderBy('opened_on')
            ->get([
                'id', 'vehicle_id', 'status', 'opened_on', 'puts_in_maintenance',
                'workshop', 'next_service_on', 'next_service_odometer',
            ]);
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
        $expectedRevenue = $canFinance
            ? (int) Contract::query()
                ->whereIn('status', ['approved', 'active'])
                ->get(['frozen_daily_rate', 'starts_at', 'planned_ends_at'])
                ->sum(function (Contract $c) {
                    return (int) $c->frozen_daily_rate * max(1, $c->starts_at->diffInDays($c->planned_ends_at));
                })
            : null;

        // مخزون: عدّاد KPI + ٦ أصناف فقط للمهام — بلا تحميل الكتالوج كامل.
        $lowStockSql = '(select coalesce(sum(remaining), 0) from inventory_batches where inventory_batches.inventory_item_id = inventory_items.id) <= inventory_items.reorder_level';
        $lowStock = (int) InventoryItem::query()->whereRaw($lowStockSql)->count();
        $lowStockItems = InventoryItem::query()
            ->where('is_active', true)
            ->select('inventory_items.*')
            ->selectRaw('(select coalesce(sum(remaining), 0) from inventory_batches where inventory_batches.inventory_item_id = inventory_items.id) as remaining_sum')
            ->whereRaw($lowStockSql)
            ->orderBy('name')
            ->limit(6)
            ->get();

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
                'fleet_vehicles' => $vehicles,
            ])
            : [];

        // tasks قديم — الواجهة تعتمد important_tasks؛ نشتقّه بلا استعلام إضافي.
        $tasks = collect($importantTasks)
            ->filter(fn (array $t) => str_starts_with((string) ($t['rule'] ?? ''), 'contract_'))
            ->take(8)
            ->values()
            ->map(function (array $t, int $i) {
                return [
                    'id' => $i + 1,
                    'title' => $t['title'],
                    'subtitle' => $t['subtitle'],
                    'status' => $t['severity'],
                    'at' => $t['due_on'],
                ];
            })
            ->all();

        $recentContracts = [];
        if ($canContracts) {
            $recent = Contract::query()
                ->with($canCustomers
                    ? ['customer:id,full_name', 'vehicle:id,brand,model,plate']
                    : ['vehicle:id,brand,model,plate'])
                ->latest('id')
                ->limit(5)
                ->get([
                    'id', 'number', 'status', 'customer_id', 'vehicle_id',
                    'starts_at', 'planned_ends_at', 'actual_ends_at',
                    'frozen_daily_rate', 'period_rent', 'final_total',
                ]);
            // من أوامر الشغل المحمّلة — بلا استعلام صيانة إضافي.
            $openMaint = [];
            $recentVehicleIds = array_flip($recent->pluck('vehicle_id')->all());
            foreach ($openWorkOrders as $wo) {
                if (! $wo->puts_in_maintenance || ! isset($recentVehicleIds[$wo->vehicle_id])) {
                    continue;
                }
                if (! isset($openMaint[$wo->vehicle_id])) {
                    $openMaint[$wo->vehicle_id] = $wo;
                }
            }
            $recentContracts = $recent->map(function (Contract $c) use ($canCustomers, $openMaint) {
                return [
                    'id' => $c->id,
                    'number' => $c->number,
                    'status' => $c->status,
                    'starts_at' => $c->starts_at?->toIso8601String(),
                    'planned_ends_at' => $c->planned_ends_at?->toIso8601String(),
                    'actual_ends_at' => $c->actual_ends_at?->toIso8601String(),
                    'frozen_daily_rate' => (int) $c->frozen_daily_rate,
                    'period_rent' => $c->period_rent !== null ? (int) $c->period_rent : null,
                    'final_total' => $c->final_total !== null ? (int) $c->final_total : null,
                    'customer' => $canCustomers && $c->customer ? [
                        'id' => $c->customer->id,
                        'full_name' => $c->customer->full_name,
                    ] : null,
                    'vehicle' => $c->vehicle ? [
                        'id' => $c->vehicle->id,
                        'brand' => $c->vehicle->brand,
                        'model' => $c->vehicle->model,
                        'plate' => $c->vehicle->plate,
                    ] : null,
                    'progress' => $this->progress->forContract($c, null, $openMaint),
                ];
            })->values()->all();
        }

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
                'expected_revenue' => $expectedRevenue,
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
            'recent_contracts' => $recentContracts,
        ];
    }
}
