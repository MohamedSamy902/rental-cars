<?php

namespace App\Services\Maintenance;

use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Notifications\DueNotificationService;
use App\Support\PaginationInput;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * جدول تغيير زيت الموتور لكل عربية — آخر تغيير + الموعد/العداد القادم.
 */
class MaintenanceOilScheduleService
{
    public const OIL_ITEM = 'زيت موتور';

    /**
     * @return array{
     *   data: list<array<string, mixed>>,
     *   meta: array{current_page: int, last_page: int, total: int, per_page: int},
     *   summary: array<string, int>
     * }
     */
    public function paginate(Request $request): array
    {
        $tab = $request->string('tab')->toString() ?: 'oil';
        $search = trim($request->string('search')->toString());
        $perPage = PaginationInput::perPage($request, 20, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);

        $latestOilIds = $this->latestOilWorkOrderIdsByVehicle();

        $q = Vehicle::query()
            ->where('is_active', true)
            ->orderBy('plate')
            ->orderBy('id');

        if ($vehicleId = $request->integer('vehicle_id')) {
            $q->where('id', $vehicleId);
        }

        if ($search !== '') {
            \App\Support\SmartSearch::constrain($q, ['plate', 'code', 'brand', 'model'], $search);
        }

        if ($tab === 'upcoming') {
            $dueVehicleIds = $this->dueVehicleIds($latestOilIds);
            if ($dueVehicleIds === []) {
                return $this->emptyPage($page, $perPage, $latestOilIds);
            }
            $q->whereIn('id', $dueVehicleIds);
        } elseif ($tab === 'oil') {
            // كل العربيات النشطة — آخر زيت أهم عمود في اللست
        }

        /** @var LengthAwarePaginator<int, Vehicle> $rows */
        $rows = $q->paginate($perPage, [
            'id', 'code', 'plate', 'brand', 'model', 'odometer', 'is_active',
        ], 'page', $page);

        $orders = WorkOrder::query()
            ->with(['lines:id,work_order_id,description'])
            ->whereIn('id', array_values(array_intersect_key(
                $latestOilIds,
                array_flip($rows->getCollection()->pluck('id')->all())
            )))
            ->get()
            ->keyBy('id');

        $data = $rows->getCollection()->map(function (Vehicle $vehicle) use ($latestOilIds, $orders) {
            $orderId = $latestOilIds[$vehicle->id] ?? null;
            $order = $orderId ? $orders->get($orderId) : null;

            return $this->presentVehicleOil($vehicle, $order);
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
            'summary' => $this->summary($latestOilIds),
        ];
    }

    /**
     * @param  array<int, int>  $latestOilIds  vehicle_id => work_order_id
     * @return array<string, int>
     */
    public function summary(array $latestOilIds = []): array
    {
        if ($latestOilIds === []) {
            $latestOilIds = $this->latestOilWorkOrderIdsByVehicle();
        }

        $open = WorkOrder::query()->whereIn('status', ['open', 'in_progress'])->count();
        $closed = WorkOrder::query()->where('status', 'closed')->count();
        $withOil = count($latestOilIds);
        $due = count($this->dueVehicleIds($latestOilIds));

        return [
            'vehicles' => (int) Vehicle::query()->where('is_active', true)->count(),
            'with_oil_history' => $withOil,
            'oil_due_soon' => $due,
            'open' => $open,
            'closed' => $closed,
        ];
    }

    /**
     * @return array<int, int> vehicle_id => latest oil work_order id
     */
    public function latestOilWorkOrderIdsByVehicle(): array
    {
        $rows = WorkOrder::query()
            ->select(['work_orders.id', 'work_orders.vehicle_id'])
            ->join('work_order_lines', 'work_order_lines.work_order_id', '=', 'work_orders.id')
            ->whereNull('work_orders.deleted_at')
            ->where('work_order_lines.description', 'like', '%'.self::OIL_ITEM.'%')
            ->orderByDesc('work_orders.opened_at')
            ->orderByDesc('work_orders.id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $vid = (int) $row->vehicle_id;
            if (! isset($map[$vid])) {
                $map[$vid] = (int) $row->id;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $latestOilIds
     * @return list<int>
     */
    private function dueVehicleIds(array $latestOilIds): array
    {
        if ($latestOilIds === []) {
            return [];
        }

        $orders = WorkOrder::query()
            ->whereIn('id', array_values($latestOilIds))
            ->get(['id', 'vehicle_id', 'next_service_on', 'next_service_odometer']);
        $vehicles = Vehicle::query()
            ->whereIn('id', array_keys($latestOilIds))
            ->get(['id', 'odometer'])
            ->keyBy('id');

        $deadline = now()->addDays(7)->toDateString();
        $due = [];
        foreach ($orders as $order) {
            $vehicle = $vehicles->get((int) $order->vehicle_id);
            if (! $vehicle) {
                continue;
            }
            if ($this->isDue($order, $vehicle, $deadline)) {
                $due[] = (int) $order->vehicle_id;
            }
        }

        return array_values(array_unique($due));
    }

    private function isDue(WorkOrder $order, Vehicle $vehicle, string $deadline): bool
    {
        if ($order->next_service_on) {
            $on = $order->next_service_on->toDateString();
            if ($on <= $deadline) {
                return true;
            }
        }
        if ($order->next_service_odometer !== null) {
            $current = (int) ($vehicle->odometer ?? 0);
            $dueOdo = (int) $order->next_service_odometer;
            if ($current >= max(0, $dueOdo - DueNotificationService::LEAD_KM)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentVehicleOil(Vehicle $vehicle, ?WorkOrder $order): array
    {
        $lastOn = null;
        $nextOn = null;
        $nextOdo = null;
        $workOrderId = null;
        $dueSoon = false;

        if ($order) {
            $workOrderId = (int) $order->id;
            $lastOn = $order->opened_on?->toDateString()
                ?? $order->opened_at?->toDateString();
            $nextOn = $order->next_service_on?->toDateString();
            $nextOdo = $order->next_service_odometer !== null
                ? (int) $order->next_service_odometer
                : null;
            $dueSoon = $this->isDue($order, $vehicle, now()->addDays(7)->toDateString());
        }

        return [
            'vehicle_id' => (int) $vehicle->id,
            'code' => $vehicle->code,
            'plate' => $vehicle->plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'odometer' => (int) ($vehicle->odometer ?? 0),
            'last_oil_change_on' => $lastOn,
            'next_oil_on' => $nextOn,
            'next_oil_odometer' => $nextOdo,
            'last_oil_work_order_id' => $workOrderId,
            'oil_due_soon' => $dueSoon,
        ];
    }

    /**
     * @param  array<int, int>  $latestOilIds
     * @return array{data: list<mixed>, meta: array<string, int>, summary: array<string, int>}
     */
    private function emptyPage(int $page, int $perPage, array $latestOilIds): array
    {
        return [
            'data' => [],
            'meta' => [
                'current_page' => $page,
                'last_page' => 1,
                'total' => 0,
                'per_page' => $perPage,
            ],
            'summary' => $this->summary($latestOilIds),
        ];
    }
}
