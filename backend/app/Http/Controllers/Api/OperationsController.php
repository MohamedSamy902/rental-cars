<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\VehicleAccessory;
use App\Models\VehicleDocument;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Models\PrintSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(private readonly VehicleAvailabilityService $availability) {}

    public function showroom(): JsonResponse
    {
        $settings = TenantSetting::query()->first();
        $flags = $settings?->feature_flags ?? [];
        abort_unless(($flags['showroom'] ?? true) === true, 403, 'وضع المعرض غير مفعّل.');

        $activeIds = \App\Models\Contract::query()->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = \App\Models\Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('planned_ends_at', '>=', now())
            ->pluck('vehicle_id')
            ->flip();

        $rows = Vehicle::query()->where('is_active', true)->orderByDesc('rental_priority')->get()
            ->map(function (Vehicle $v) use ($activeIds, $reservedIds) {
                $status = $this->availability->statusOnDate([
                    'manual_status' => $v->manual_status,
                    'has_active_handover' => $activeIds->has($v->id),
                    'has_approved_covering_date' => $reservedIds->has($v->id),
                    'open_work_order_blocks' => $v->manual_status === 'maintenance',
                ]);

                return [
                    'id' => $v->id,
                    'brand' => $v->brand,
                    'model' => $v->model,
                    'year' => $v->year,
                    'category' => $v->category,
                    'fuel_type' => $v->fuel_type,
                    'status' => $status,
                    'available' => $status === 'available',
                ];
            })
            ->filter(fn ($row) => $row['available'])
            ->values();

        return response()->json([
            'data' => [
                'center_name' => $settings?->center_name,
                'vehicles' => $rows,
            ],
        ]);
    }

    public function departmentNotice(Request $request): JsonResponse
    {
        $settings = TenantSetting::query()->first();
        $flags = $settings?->feature_flags ?? [];
        abort_unless(($flags['department_notice'] ?? true) === true, 403, 'إخطار القسم غير مفعّل.');

        $filter = $request->string('filter', 'all')->toString();
        $q = Customer::query()->orderBy('full_name');

        if ($filter === 'one') {
            $id = (int) $request->input('customer_id');
            $q->whereKey($id);
        } elseif ($filter === 'today') {
            $q->whereHas('contracts', fn ($c) => $c->whereDate('starts_at', now()->toDateString()));
        } elseif ($filter === 'week') {
            $q->whereHas('contracts', fn ($c) => $c->whereBetween('starts_at', [now()->startOfWeek(), now()->endOfWeek()]));
        } elseif ($filter === 'range') {
            $from = $request->date('from');
            $to = $request->date('to');
            if ($from && $to) {
                $q->whereHas('contracts', fn ($c) => $c->whereBetween('starts_at', [$from, $to]));
            }
        }

        $rows = $q->limit(200)->get([
            'id', 'full_name', 'phone', 'national_id', 'national_id_image_path',
            'license_number', 'license_expires_on', 'license_image_path', 'birth_date', 'status',
        ]);

        return response()->json([
            'data' => [
                'center_name' => $settings?->center_name,
                'generated_at' => now()->toIso8601String(),
                'filter' => $filter,
                'customers' => $rows,
            ],
        ]);
    }

    public function updateOperational(Request $request): JsonResponse
    {
        $data = $request->validate([
            'center_name' => ['sometimes', 'string', 'max:200'],
            'print_footer' => ['nullable', 'string', 'max:300'],
            'operational' => ['nullable', 'array'],
        ]);

        $settings = TenantSetting::query()->firstOrCreate([]);
        $settings->fill($data)->save();

        return response()->json(['data' => $settings->fresh(), 'message' => 'تم حفظ إعدادات التشغيل.']);
    }

    public function updateIntegrator(Request $request): JsonResponse
    {
        $data = $request->validate([
            'center_name' => ['sometimes', 'string', 'max:200'],
            'print_footer' => ['nullable', 'string', 'max:300'],
            'multi_branch' => ['sometimes', 'boolean'],
            'inventory_mode' => ['sometimes', 'in:unified,per_branch'],
            'colors' => ['nullable', 'array'],
            'feature_flags' => ['nullable', 'array'],
            'logo_path' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = TenantSetting::query()->firstOrCreate([]);
        $settings->fill($data)->save();

        return response()->json(['data' => $settings->fresh(), 'message' => 'تم حفظ إعدادات المُدمج.']);
    }

    /** @deprecated استخدم updateOperational أو updateIntegrator */
    public function updateTenant(Request $request): JsonResponse
    {
        if ($request->user()?->role?->name === 'super_admin' && (
            $request->exists('feature_flags') || $request->exists('colors') || $request->exists('multi_branch')
        )) {
            return $this->updateIntegrator($request);
        }

        return $this->updateOperational($request);
    }

    public function estimatedOdometer(Request $request, Vehicle $vehicle): JsonResponse
    {
        $active = $vehicle->contracts()->where('status', 'active')->latest('id')->first();
        if (! $active || ! $active->odometer_out || ! $active->starts_at) {
            return response()->json([
                'data' => [
                    'vehicle_id' => $vehicle->id,
                    'estimated' => (int) $vehicle->odometer,
                    'basis' => 'recorded',
                    'note' => 'لا عقد نشط — يُعرض العداد المسجّل.',
                ],
            ]);
        }

        $days = max(1, $active->starts_at->diffInDays(now()) + 1);
        $dailyKm = (int) ($active->frozen_daily_km_allowance ?: 200);
        $estimated = (int) $active->odometer_out + ($days * $dailyKm);

        return response()->json([
            'data' => [
                'vehicle_id' => $vehicle->id,
                'contract_id' => $active->id,
                'odometer_out' => (int) $active->odometer_out,
                'days_elapsed' => $days,
                'daily_km_allowance' => $dailyKm,
                'estimated' => $estimated,
                'basis' => 'active_contract_projection',
                'note' => 'تقدير أثناء التأجير للتنبيه فقط — المرجع الفعلي عند الاستلام.',
            ],
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $rows = AuditLog::query()
            ->latest('id')
            ->limit((int) $request->input('limit', 50))
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function pettyCashIndex(): JsonResponse
    {
        $rows = PettyCashAccount::query()->with('employee')->orderByDesc('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function pettyCashStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'limit_amount' => ['required', 'integer', 'min:0'],
            'balance' => ['nullable', 'integer', 'min:0'],
        ], [], ['employee_id' => 'الموظف', 'limit_amount' => 'حد العهدة']);

        $row = PettyCashAccount::query()->updateOrCreate(
            ['employee_id' => $data['employee_id']],
            [
                'limit_amount' => $data['limit_amount'],
                'balance' => $data['balance'] ?? $data['limit_amount'],
                'is_active' => true,
            ],
        );

        return response()->json(['data' => $row->load('employee'), 'message' => 'تم حفظ العهدة.'], 201);
    }

    public function pettyCashMove(Request $request, PettyCashAccount $account): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:topup,spend,adjust'],
            'amount' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ], [], ['type' => 'نوع الحركة', 'amount' => 'المبلغ']);

        $row = DB::transaction(function () use ($account, $data, $request) {
            $amount = (int) $data['amount'];
            $balance = (int) $account->balance;
            if ($data['type'] === 'topup') {
                $balance += $amount;
            } elseif ($data['type'] === 'spend') {
                if ($amount > $balance) {
                    abort(422, 'رصيد العهدة غير كافٍ.');
                }
                $balance -= $amount;
            } else {
                $balance = $amount;
            }

            $account->update(['balance' => $balance]);

            return PettyCashMovement::query()->create([
                'petty_cash_account_id' => $account->id,
                'type' => $data['type'],
                'amount' => $amount,
                'moved_on' => now()->toDateString(),
                'performed_by' => $request->user()?->id,
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json(['data' => ['movement' => $row, 'account' => $account->fresh('employee')], 'message' => 'تم تسجيل حركة العهدة.']);
    }

    public function storeVehicleDocument(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:100'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ], [], ['type' => 'نوع المستند']);

        $doc = $vehicle->documents()->create([
            'type' => $data['type'],
            'file_path' => $data['file_path'] ?? 'manual/'.$data['type'],
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $doc, 'message' => 'تم تسجيل المستند.'], 201);
    }

    public function storeVehicleAccessory(Request $request, Vehicle $vehicle): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'default_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'required_each_rental' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['name' => 'اسم المستلزم', 'default_qty' => 'الكمية']);

        $row = $vehicle->accessories()->create([
            'name' => $data['name'],
            'default_qty' => $data['default_qty'] ?? 1,
            'required_each_rental' => $data['required_each_rental'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        return response()->json(['data' => $row, 'message' => 'تم إضافة المستلزم.'], 201);
    }

    public function updateVehicleAccessory(Request $request, Vehicle $vehicle, int $accessory): JsonResponse
    {
        $row = $vehicle->accessories()->whereKey($accessory)->firstOrFail();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'default_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'required_each_rental' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], ['name' => 'اسم المستلزم', 'default_qty' => 'الكمية']);

        $row->update($data);

        return response()->json(['data' => $row->fresh(), 'message' => 'تم تحديث المستلزم.']);
    }

    public function destroyVehicleAccessory(Vehicle $vehicle, int $accessory): JsonResponse
    {
        $row = $vehicle->accessories()->whereKey($accessory)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'تم حذف المستلزم.']);
    }

    public function updateVehicleDocument(Request $request, Vehicle $vehicle, int $document): JsonResponse
    {
        $row = $vehicle->documents()->whereKey($document)->firstOrFail();
        $data = $request->validate([
            'type' => ['sometimes', 'string', 'max:100'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'issued_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ], [], ['type' => 'نوع المستند']);

        $row->update($data);

        return response()->json(['data' => $row->fresh(), 'message' => 'تم تحديث المستند.']);
    }

    public function destroyVehicleDocument(Vehicle $vehicle, int $document): JsonResponse
    {
        $row = $vehicle->documents()->whereKey($document)->firstOrFail();
        $row->delete();

        return response()->json(['message' => 'تم حذف المستند.']);
    }

    public function qrPayload(Request $request): JsonResponse
    {
        $type = $request->string('type', 'vehicle')->toString();
        $id = (int) $request->input('id');

        if ($type === 'vehicle') {
            $v = Vehicle::query()->findOrFail($id);

            return response()->json([
                'data' => [
                    'qr' => url('/showroom?vehicle='.$v->id),
                    'label' => $v->brand.' '.$v->model.' · '.$v->plate,
                ],
            ]);
        }

        $c = Customer::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'qr' => url('/department-notice?customer_id='.$c->id),
                'label' => $c->full_name,
            ],
        ]);
    }

    public function employeesLookup(): JsonResponse
    {
        return response()->json([
            'data' => Employee::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'job_title']),
        ]);
    }

    public function storePrintSnapshot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:contract,invoice,receipt,handover,department_notice'],
            'record_type' => ['required', 'string', 'max:120'],
            'record_id' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
        ], [], ['type' => 'نوع المستند']);

        $row = PrintSnapshot::query()->create([
            ...$data,
            'printed_by' => $request->user()?->id,
            'printed_at' => now(),
        ]);

        return response()->json(['data' => $row, 'message' => 'تم حفظ نسخة الطباعة.'], 201);
    }
}
