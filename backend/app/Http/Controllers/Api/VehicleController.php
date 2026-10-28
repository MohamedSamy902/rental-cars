<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function __construct(private readonly VehicleAvailabilityService $availability) {}

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:vehicles,code'],
            'plate' => ['required', 'string', 'max:50', 'unique:vehicles,plate'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1990', 'max:2100'],
            'category' => ['required', 'string', 'max:100'],
            'fuel_type' => ['required', 'string', 'max:50'],
            'odometer' => ['required', 'integer', 'min:0'],
            'daily_rate' => ['required', 'integer', 'min:0'],
            'default_deposit' => ['required', 'integer', 'min:0'],
            'rental_priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tracker_device_number' => ['nullable', 'string', 'max:100'],
            'tracker_sim_number' => ['nullable', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'max:50'],
            'transmission' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:vehicle_owners,id'],
            'owner_monthly_amount' => ['nullable', 'integer', 'min:0'],
            'owner_since' => ['nullable', 'date'],
        ], [], [
            'code' => 'الكود الداخلي',
            'plate' => 'رقم اللوحة',
            'brand' => 'الماركة',
            'model' => 'الموديل',
            'year' => 'سنة الصنع',
            'category' => 'الفئة',
            'fuel_type' => 'نوع الوقود',
            'odometer' => 'العداد الفعلي',
            'daily_rate' => 'الإيجار اليومي',
            'default_deposit' => 'التأمين النقدي',
            'owner_id' => 'المالك',
            'owner_monthly_amount' => 'المبلغ الشهري للمالك',
        ]);

        if (! empty($data['owner_id']) && empty($data['owner_since'])) {
            $data['owner_since'] = now()->toDateString();
        }
        if (empty($data['owner_id'])) {
            $data['owner_id'] = null;
            $data['owner_monthly_amount'] = $data['owner_monthly_amount'] ?? null;
        }

        $vehicle = Vehicle::query()->create([
            ...$data,
            'odometer_source' => 'manual',
            'rental_priority' => $data['rental_priority'] ?? 50,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return response()->json(['data' => $this->present($vehicle->load('owner')), 'message' => 'تم إضافة السيارة.'], 201);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        return response()->json(['data' => $this->present($vehicle->load(['accessories', 'documents', 'owner']))]);
    }

    public function update(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', 'unique:vehicles,code,'.$vehicle->id],
            'plate' => ['sometimes', 'string', 'max:50', 'unique:vehicles,plate,'.$vehicle->id],
            'brand' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'string', 'max:100'],
            'year' => ['sometimes', 'integer', 'min:1990', 'max:2100'],
            'category' => ['sometimes', 'string', 'max:100'],
            'fuel_type' => ['sometimes', 'string', 'max:50'],
            'odometer' => ['sometimes', 'integer', 'min:0'],
            'daily_rate' => ['sometimes', 'integer', 'min:0'],
            'default_deposit' => ['sometimes', 'integer', 'min:0'],
            'rental_priority' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tracker_device_number' => ['nullable', 'string', 'max:100'],
            'tracker_sim_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'owner_id' => ['nullable', 'integer', 'exists:vehicle_owners,id'],
            'owner_monthly_amount' => ['nullable', 'integer', 'min:0'],
            'owner_since' => ['nullable', 'date'],
        ], [], [
            'owner_id' => 'المالك',
            'owner_monthly_amount' => 'المبلغ الشهري للمالك',
        ]);

        if (array_key_exists('owner_id', $data)) {
            if ($data['owner_id'] && ! $vehicle->owner_id) {
                $data['owner_since'] = $data['owner_since'] ?? now()->toDateString();
            }
            if (! $data['owner_id']) {
                $data['owner_id'] = null;
                if (! array_key_exists('owner_monthly_amount', $data)) {
                    $data['owner_monthly_amount'] = null;
                }
            }
        }

        $vehicle->update($data);

        return response()->json(['data' => $this->present($vehicle->fresh()->load('owner')), 'message' => 'تم تحديث السيارة.']);
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
            'open_work_order_blocks' => $v->manual_status === 'maintenance',
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
            'odometer' => $v->odometer,
            'daily_rate' => $v->daily_rate,
            'default_deposit' => $v->default_deposit,
            'rental_priority' => $v->rental_priority,
            'tracker_device_number' => $v->tracker_device_number,
            'tracker_sim_number' => $v->tracker_sim_number,
            'is_active' => $v->is_active,
            'status' => $status,
            'owner_id' => $v->owner_id,
            'owner_monthly_amount' => $v->owner_monthly_amount,
            'owner_since' => $v->owner_since?->toDateString(),
            'owner' => $v->relationLoaded('owner') && $v->owner ? [
                'id' => $v->owner->id,
                'full_name' => $v->owner->full_name,
                'phone' => $v->owner->phone,
            ] : null,
            'accessories' => $v->relationLoaded('accessories') ? $v->accessories : null,
            'documents' => $v->relationLoaded('documents') ? $v->documents : null,
            'notes' => $v->notes,
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
