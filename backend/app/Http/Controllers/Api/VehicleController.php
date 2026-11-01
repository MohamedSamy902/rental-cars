<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\MediaFile;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Fleet\VehicleAssetService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Fleet\VehicleRecommendationService;
use App\Services\Fleet\VehicleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleAvailabilityService $availability,
        private readonly VehicleRecommendationService $recommendation,
        private readonly VehicleAssetService $assets,
        private readonly VehicleService $vehicles,
    ) {}

    public function catalogs(): JsonResponse
    {
        return response()->json([
            'data' => [
                'brands' => $this->vehicles->brandOptions(),
                'addons' => $this->vehicles->addonCatalog(),
                'next_code' => $this->vehicles->nextInternalCode(),
            ],
        ]);
    }

    public function recommend(Request $request): JsonResponse
    {
        $category = $request->string('category')->toString() ?: null;
        $from = $request->filled('from') ? \Carbon\Carbon::parse((string) $request->input('from')) : null;
        $to = $request->filled('to') ? \Carbon\Carbon::parse((string) $request->input('to')) : null;

        return response()->json([
            'data' => $this->recommendation->rankAvailable($category ?: null, null, $from, $to),
            'weights' => $this->recommendation->weights(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $q = Vehicle::query()->latest('id');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('code', 'like', "%{$search}%")
                    ->orWhere('plate', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            if ($status === 'maintenance') {
                $q->where('manual_status', 'maintenance');
            } elseif ($status === 'available') {
                $q->where(function ($qq) {
                    $qq->whereNull('manual_status')->orWhere('manual_status', '');
                })->whereDoesntHave('contracts', function ($c) {
                    $c->whereIn('status', ['approved', 'active'])
                        ->where('starts_at', '<=', now())
                        ->where('planned_ends_at', '>=', now());
                });
            } elseif ($status === 'rented') {
                $q->whereHas('contracts', fn ($c) => $c->where('status', 'active'));
            } elseif ($status === 'reserved') {
                $q->whereHas('contracts', fn ($c) => $c->where('status', 'approved'));
            }
        }

        $q->with('owner:id,full_name,phone');
        $rows = $q->paginate((int) $request->integer('per_page', 50));

        $data = collect($rows->items())->map(function (Vehicle $v) {
            return $this->present($v);
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ownerInline = $data['owner_inline'] ?? null;
        $addonIds = $data['addon_ids'] ?? [];
        unset($data['owner_inline'], $data['addon_ids']);

        try {
            $vehicle = $this->vehicles->create($data, is_array($ownerInline) ? $ownerInline : null, is_array($addonIds) ? $addonIds : []);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($vehicle), 'message' => 'تم إضافة السيارة.'], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return response()->json(['data' => $this->present($vehicle->load(['accessories', 'documents', 'owner']))]);
    }

    /** ملف العربية الكامل: حجوزات · صيانة · مصروفات · مرفقات · مالك · QR */
    public function dossier(Vehicle $vehicle): JsonResponse
    {
        $vehicle->load(['accessories', 'documents', 'owner']);

        $contracts = Contract::query()
            ->with(['customer:id,full_name,phone', 'deposit'])
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Contract $c) => [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toIso8601String(),
                'planned_ends_at' => $c->planned_ends_at?->toIso8601String(),
                'actual_ends_at' => $c->actual_ends_at?->toIso8601String(),
                'final_total' => $c->final_total,
                'period_rent' => $c->period_rent,
                'odometer_out' => $c->odometer_out,
                'odometer_in' => $c->odometer_in,
                'customer' => $c->customer ? [
                    'id' => $c->customer->id,
                    'full_name' => $c->customer->full_name,
                    'phone' => $c->customer->phone,
                ] : null,
            ]);

        $workOrders = WorkOrder::query()
            ->with('lines')
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (WorkOrder $w) => [
                'id' => $w->id,
                'status' => $w->status,
                'workshop' => $w->workshop,
                'opened_on' => $w->opened_on?->toDateString(),
                'closed_on' => $w->closed_on?->toDateString(),
                'odometer' => $w->odometer,
                'parts_cost' => $w->parts_cost,
                'labor_cost' => $w->labor_cost,
                'notes' => $w->notes,
                'lines' => $w->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => $l->description,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unit_price,
                ])->values(),
            ]);

        $expenses = Expense::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'title' => $e->title,
                'category' => $e->category,
                'amount' => $e->amount,
                'spent_on' => $e->spent_on?->toDateString(),
                'payment_method' => $e->payment_method,
                'notes' => $e->notes,
            ]);

        $violations = Violation::query()
            ->with('customer:id,full_name')
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Violation $v) => [
                'id' => $v->id,
                'status' => $v->status,
                'amount' => $v->amount,
                'location' => $v->location,
                'occurred_at' => $v->occurred_at?->toIso8601String(),
                'customer' => $v->customer ? [
                    'id' => $v->customer->id,
                    'full_name' => $v->customer->full_name,
                ] : null,
            ]);

        $media = MediaFile::query()
            ->where('attachable_type', Vehicle::class)
            ->where('attachable_id', $vehicle->id)
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (MediaFile $m) => [
                'id' => $m->id,
                'kind' => $m->kind,
                'original_name' => $m->original_name,
                'collection' => $m->collection,
                'purpose' => $m->purpose,
                'mime_type' => $m->mime_type,
                'size_bytes' => $m->size_bytes,
                'url' => url('/api/media/'.$m->id.'/file'),
                'created_at' => $m->created_at?->toIso8601String(),
            ]);

        $ownerDues = OwnerMonthDue::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('year_month')
            ->limit(36)
            ->get()
            ->map(fn (OwnerMonthDue $d) => [
                'id' => $d->id,
                'year_month' => $d->year_month,
                'amount' => $d->amount,
            ]);

        $ownerPayouts = OwnerPayout::query()
            ->where('vehicle_id', $vehicle->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (OwnerPayout $p) => [
                'id' => $p->id,
                'amount' => $p->amount,
                'paid_on' => $p->paid_on?->toDateString() ?? $p->paid_on,
                'payment_method' => $p->payment_method,
                'notes' => $p->notes,
            ]);

        $qr = url('/showroom?vehicle='.$vehicle->id);
        $qrLabel = trim($vehicle->brand.' '.$vehicle->model.' · '.$vehicle->plate);

        $contractStatus = $contracts->groupBy('status')->map(fn ($rows, $status) => [
            'status' => $status,
            'label' => $status,
            'count' => $rows->count(),
        ])->values();

        $months = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths(11 - $i)->format('Y-m'));

        $expensesByMonth = $months->map(function (string $ym) use ($expenses) {
            $sum = $expenses->filter(fn ($e) => is_string($e['spent_on'] ?? null) && str_starts_with((string) $e['spent_on'], $ym))
                ->sum('amount');

            return ['month' => $ym, 'amount' => (int) $sum];
        });

        $maintenanceByMonth = $months->map(function (string $ym) use ($workOrders) {
            $rows = $workOrders->filter(fn ($w) => is_string($w['opened_on'] ?? null) && str_starts_with((string) $w['opened_on'], $ym));
            $cost = $rows->sum(fn ($w) => (int) ($w['parts_cost'] ?? 0) + (int) ($w['labor_cost'] ?? 0));

            return ['month' => $ym, 'amount' => (int) $cost, 'count' => $rows->count()];
        });

        $revenueByMonth = $months->map(function (string $ym) use ($contracts) {
            $sum = $contracts->filter(function ($c) use ($ym) {
                $start = is_string($c['starts_at'] ?? null) ? substr((string) $c['starts_at'], 0, 7) : null;

                return $start === $ym && in_array($c['status'], ['active', 'completed', 'approved'], true);
            })->sum(fn ($c) => (int) ($c['final_total'] ?? $c['period_rent'] ?? 0));

            return ['month' => $ym, 'amount' => (int) $sum];
        });

        $assetExtras = $this->assets->dossierExtras($vehicle);

        return response()->json([
            'data' => [
                'vehicle' => $this->present($vehicle),
                'contracts' => $contracts->values(),
                'work_orders' => $workOrders->values(),
                'expenses' => $expenses->values(),
                'violations' => $violations->values(),
                'media' => $media->values(),
                'owner_dues' => $ownerDues->values(),
                'owner_payouts' => $ownerPayouts->values(),
                'tires' => $assetExtras['tires'],
                'batteries' => $assetExtras['batteries'],
                'downtime' => $assetExtras['downtime'],
                'consumables' => $assetExtras['consumables'],
                'asset_defaults' => $assetExtras['defaults'],
                'qr' => [
                    'payload' => $qr,
                    'label' => $qrLabel,
                ],
                'charts' => [
                    'contracts_by_status' => $contractStatus,
                    'expenses_by_month' => $expensesByMonth->values(),
                    'maintenance_by_month' => $maintenanceByMonth->values(),
                    'revenue_by_month' => $revenueByMonth->values(),
                ],
                'totals' => [
                    'contracts' => $contracts->count(),
                    'work_orders' => $workOrders->count(),
                    'expenses_amount' => $expenses->sum('amount'),
                    'violations' => $violations->count(),
                    'media' => $media->count(),
                    'maintenance_cost' => $workOrders->sum(fn ($w) => (int) ($w['parts_cost'] ?? 0) + (int) ($w['labor_cost'] ?? 0)),
                    'revenue_amount' => $contracts
                        ->filter(fn ($c) => in_array($c['status'], ['active', 'completed', 'approved'], true))
                        ->sum(fn ($c) => (int) ($c['final_total'] ?? $c['period_rent'] ?? 0)),
                    'accessories' => $vehicle->accessories->count(),
                    'documents' => $vehicle->documents->count(),
                    'lost_revenue' => collect($assetExtras['downtime'])->sum(fn ($d) => (int) ($d->lost_revenue_estimate ?? 0)),
                ],
            ],
        ]);
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('delete', $vehicle);
        $hasActive = $vehicle->contracts()
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();
        if ($hasActive) {
            return response()->json(['message' => 'لا يمكن أرشفة السيارة لوجود عقود نشطة.'], 422);
        }

        // تحرير code/plate من الفهرس الفريد الكامل حتى يمكن إعادة استخدامهما بعد الأرشفة (توافق MySQL).
        $suffix = '__del_'.$vehicle->id;
        $vehicle->forceFill([
            'code' => mb_substr($vehicle->code, 0, 40).$suffix,
            'plate' => mb_substr($vehicle->plate, 0, 40).$suffix,
            'is_active' => false,
        ])->save();
        $vehicle->delete();

        return response()->json(['message' => 'تم أرشفة السيارة.']);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        try {
            $vehicle = $this->vehicles->update($vehicle, $request->validated());
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($vehicle), 'message' => 'تم تحديث السيارة.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Vehicle $v): array
    {
        $status = $this->availability->statusOnDate([
            'manual_status' => $v->manual_status,
            'has_active_handover' => $v->contracts()->where('status', 'active')->exists(),
            'has_approved_covering_date' => $v->contracts()
                ->where('status', 'approved')
                ->where('starts_at', '<=', now())
                ->where('planned_ends_at', '>=', now())
                ->exists(),
            'open_work_order_blocks' => $v->manual_status === 'maintenance'
                || WorkOrder::query()
                    ->where('vehicle_id', $v->id)
                    ->where('puts_in_maintenance', true)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists(),
        ]);

        return [
            'id' => $v->id,
            'code' => $v->code,
            'plate' => $v->plate,
            'brand' => $v->brand,
            'model' => $v->model,
            'year' => $v->year,
            'category' => $v->category,
            'fuel_type' => $v->fuel_type,
            'color' => $v->color,
            'transmission' => $v->transmission,
            'chassis_number' => $v->chassis_number,
            'engine_number' => $v->engine_number,
            'engine_capacity' => $v->engine_capacity,
            'seats' => $v->seats,
            'odometer' => $v->odometer,
            'odometer_source' => $v->odometer_source,
            'daily_rate' => $v->daily_rate,
            'default_deposit' => $v->default_deposit,
            'target_monthly_income' => $v->target_monthly_income,
            'purchase_value_estimate' => $v->purchase_value_estimate,
            'rental_priority' => $v->rental_priority,
            'tracker_device_number' => $v->tracker_device_number,
            'tracker_sim_number' => $v->tracker_sim_number,
            'manual_status' => $v->manual_status,
            'is_active' => $v->is_active,
            'status' => $status,
            'owner_id' => $v->owner_id !== null ? (int) $v->owner_id : null,
            'owner_monthly_amount' => $v->owner_monthly_amount !== null ? (int) $v->owner_monthly_amount : null,
            'owner_since' => $v->owner_since?->toDateString(),
            'owner' => $v->relationLoaded('owner') && $v->owner ? [
                'id' => $v->owner->id,
                'full_name' => $v->owner->full_name,
                'phone' => $v->owner->phone,
            ] : null,
            'accessories' => $v->relationLoaded('accessories') ? $v->accessories : null,
            'documents' => $v->relationLoaded('documents') ? $v->documents : null,
            'notes' => $v->notes,
            'created_at' => $v->created_at?->toIso8601String(),
            'updated_at' => $v->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        $all = Vehicle::query()->where('is_active', true)->get();
        $counts = ['total' => $all->count(), 'available' => 0, 'rented' => 0, 'reserved' => 0, 'maintenance' => 0];
        foreach ($all as $v) {
            $status = $this->present($v)['status'];
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
