<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Models\Vehicle;
use App\Services\Maintenance\MaintenanceOilScheduleService;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Notifications\DueNotificationService;
use App\Support\MaintenanceContractLabel;
use App\Support\PaginationInput;
use App\Support\VehicleOwnerVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderService $service,
        private readonly MaintenanceOilScheduleService $oilSchedule,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $view = $request->string('view')->toString();
        if ($view === 'oil' || in_array($request->string('tab')->toString(), ['oil', 'upcoming'], true)) {
            return response()->json($this->oilSchedule->paginate($request));
        }

        $q = WorkOrder::query()->with(['vehicle:id,brand,model,plate,code,odometer', 'lines'])->latest('id');
        if ($status = $request->string('status')->toString()) {
            if ($status === 'open') {
                $q->whereIn('status', ['open', 'in_progress']);
            } else {
                $q->where('status', $status);
            }
        }
        $vehicleId = $request->integer('vehicle_id');
        if ($vehicleId) {
            $q->where('vehicle_id', $vehicleId);
        }
        // صفحة صيانات العربية: أوامر ذات صيانة تالية مستحقة (≤٧ أيام أو ضمن ٢٬٠٠٠ كم)
        if ($request->boolean('due_soon') && $vehicleId) {
            $vehicle = Vehicle::query()->find($vehicleId, ['id', 'odometer']);
            $deadline = now()->addDays(7)->toDateString();
            $odoCap = $vehicle
                ? (int) ($vehicle->odometer ?? 0) + DueNotificationService::LEAD_KM
                : null;
            $q->where(function ($qq) use ($deadline, $odoCap) {
                $qq->where(function ($d) use ($deadline) {
                    $d->whereNotNull('next_service_on')
                        ->whereDate('next_service_on', '<=', $deadline);
                });
                if ($odoCap !== null) {
                    $qq->orWhere(function ($o) use ($odoCap) {
                        $o->whereNotNull('next_service_odometer')
                            ->where('next_service_odometer', '<=', $odoCap);
                    });
                }
            });
        }
        if ($request->boolean('has_oil')) {
            $oil = MaintenanceOilScheduleService::OIL_ITEM;
            $q->whereHas('lines', fn ($l) => $l->where('description', 'like', "%{$oil}%"));
        }
        if ($search = trim($request->string('search')->toString())) {
            $q->where(function ($qq) use ($search) {
                \App\Support\SmartSearch::constrain($qq, ['workshop'], $search);
                $qq->orWhereHas('vehicle', function ($v) use ($search) {
                    \App\Support\SmartSearch::constrain($v, ['plate', 'code', 'brand', 'model'], $search);
                })
                    ->orWhereHas('lines', function ($l) use ($search) {
                        \App\Support\SmartSearch::constrain($l, ['description'], $search);
                    });
            });
        }
        $perPage = PaginationInput::perPage($request, 20, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = $q->paginate($perPage, [
            'id', 'vehicle_id', 'workshop', 'opened_on', 'opened_at', 'closed_on', 'odometer',
            'status', 'puts_in_maintenance', 'notes', 'next_service_on', 'next_service_odometer',
        ], 'page', $page);

        $oilSummary = $this->oilSchedule->summary();

        return response()->json([
            'data' => collect($rows->items())->map(fn (WorkOrder $order) => $this->presentMaintenance($order, $request))->values(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
            'summary' => [
                'open' => $oilSummary['open'],
                'open_only' => WorkOrder::query()->where('status', 'open')->count(),
                'in_progress' => WorkOrder::query()->where('status', 'in_progress')->count(),
                'closed' => $oilSummary['closed'],
                'vehicles' => $oilSummary['vehicles'],
                'with_oil_history' => $oilSummary['with_oil_history'],
                'oil_due_soon' => $oilSummary['oil_due_soon'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'exists:vehicles,id'],
            'workshop' => ['nullable', 'string'],
            'opened_on' => ['nullable', 'date'],
            'opened_at' => ['nullable', 'date'],
            'odometer' => ['required', 'integer', 'min:0'],
            'puts_in_maintenance' => ['nullable', 'boolean'],
            'labor_cost' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'baseline' => ['nullable', 'boolean'],
            'next_service_on' => ['nullable', 'date', 'required_without:next_service_odometer', 'prohibits:next_service_odometer'],
            'next_service_odometer' => ['nullable', 'integer', 'min:0', 'required_without:next_service_on', 'prohibits:next_service_on'],
            'lines' => ['nullable', 'array'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.part_source' => ['required_with:lines', 'in:stock,purchase'],
            'lines.*.inventory_item_id' => [
                'nullable',
                'integer',
                'min:1',
                'required_if:lines.*.part_source,stock',
                'exists:inventory_items,id',
            ],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1'],
            'lines.*.unit_price' => ['nullable', 'integer', 'min:0'],
            'lines.*.from_petty_cash' => ['nullable', 'boolean'],
            'lines.*.petty_cash_employee_id' => ['nullable', 'integer'],
            'lines.*.petty_cash_amount' => ['nullable', 'integer', 'min:0'],
        ], [
            'lines.*.inventory_item_id.required_if' => 'اختر صنف المخزون عند التمويل من المخزون.',
            'lines.*.inventory_item_id.exists' => 'صنف المخزون المحدد غير موجود.',
            'lines.*.inventory_item_id.min' => 'اختر صنف المخزون.',
            'lines.*.inventory_item_id.integer' => 'اختر صنف المخزون.',
            'vehicle_id.required' => 'العربية مطلوبة.',
            'lines.*.quantity.required' => 'الكمية مطلوبة.',
            'next_service_on.required_without' => 'حدّد موعد أو عداد الصيانة التالية (واحد فقط).',
            'next_service_odometer.required_without' => 'حدّد موعد أو عداد الصيانة التالية (واحد فقط).',
            'next_service_on.prohibits' => 'اختر واحداً فقط: موعد أو عداد الصيانة التالية.',
            'next_service_odometer.prohibits' => 'اختر واحداً فقط: موعد أو عداد الصيانة التالية.',
        ], [
            'vehicle_id' => 'العربية',
            'odometer' => 'العداد',
            'opened_at' => 'تاريخ ووقت الصيانة',
            'next_service_on' => 'موعد الصيانة التالية',
            'next_service_odometer' => 'عداد الصيانة التالية',
            'lines.*.inventory_item_id' => 'صنف المخزون',
            'lines.*.part_source' => 'مصدر القطع',
            'lines.*.description' => 'وصف البند',
            'lines.*.quantity' => 'الكمية',
        ]);

        $usesPetty = collect($data['lines'] ?? [])->contains(fn ($line) => ! empty($line['from_petty_cash']));
        if ($usesPetty && ! $request->user()?->hasPermission('petty_cash.manage') && ! $request->user()?->hasPermission('hr.custody')) {
            return response()->json(['message' => 'صرف العهدة يتطلب صلاحية إدارة العهدة.'], 403);
        }

        $isBaseline = (bool) ($data['baseline'] ?? false)
            || (
                ($data['puts_in_maintenance'] ?? true) === false
                && str_contains((string) ($data['notes'] ?? ''), 'تسجيل أساس')
            );

        if (! $isBaseline) {
            $fundingError = $this->assertFundedLines($data['lines'] ?? []);
            if ($fundingError !== null) {
                return response()->json(['message' => $fundingError], 422);
            }
        }

        try {
            $order = $this->service->open($data, $data['lines'] ?? [], $request->user()?->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($order, $request), 'message' => 'تم فتح أمر الشغل.'], 201);
    }

    public function close(WorkOrder $workOrder): JsonResponse
    {
        if ($workOrder->status === 'closed') {
            return response()->json(['message' => 'مغلق مسبقاً.'], 422);
        }
        $order = $this->service->close($workOrder->load('lines'));

        return response()->json(['data' => $this->present($order, request()), 'message' => 'تم إغلاق أمر الشغل.']);
    }

    /**
     * Regular work orders must fund from stock or petty cash with a non-zero amount.
     * Baseline (first upcoming) is exempt and may use zero-price documentation lines.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function assertFundedLines(array $lines): ?string
    {
        if ($lines === []) {
            return 'أمر الصيانة العادي يتطلب بند تمويل من المخزون أو العهدة.';
        }

        foreach ($lines as $line) {
            $source = (string) ($line['part_source'] ?? '');
            if ($source === 'stock') {
                if (empty($line['inventory_item_id'])) {
                    return 'اختر صنف المخزون عند التمويل من المخزون.';
                }

                continue;
            }

            if ($source === 'purchase') {
                $fromPetty = ! empty($line['from_petty_cash']);
                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $unit = (int) ($line['unit_price'] ?? 0);
                $pettyAmount = (int) ($line['petty_cash_amount'] ?? 0);
                $total = $pettyAmount > 0 ? $pettyAmount : ($unit * $qty);
                if (! $fromPetty) {
                    return 'أمر الصيانة العادي يتطلب مصدر فلوس: مخزون أو عهدة.';
                }
                if ($total < 1) {
                    return 'مبلغ الصيانة من العهدة لازم يكون أكبر من صفر.';
                }

                continue;
            }

            return 'أمر الصيانة العادي يتطلب مصدر فلوس: مخزون أو عهدة.';
        }

        return null;
    }

    /**
     * قائمة/تفاصيل الصيانة للمشغّلين — بلا مبالغ مالية (صلاحية الصيانة فقط).
     *
     * @return array<string, mixed>
     */
    private function presentMaintenance(WorkOrder $order, Request $request): array
    {
        $canContracts = (bool) $request->user()?->hasPermission('contracts.view');
        $items = $order->relationLoaded('lines')
            ? $order->lines->map(fn ($line) => MaintenanceContractLabel::present($line->description, $canContracts))->filter()->values()->all()
            : [];

        $payload = [
            'id' => (int) $order->id,
            'vehicle_id' => (int) $order->vehicle_id,
            'status' => $order->status,
            'workshop' => MaintenanceContractLabel::present($order->workshop, $canContracts),
            'opened_on' => $order->opened_on?->toDateString(),
            'opened_at' => $order->opened_at?->toIso8601String(),
            'closed_on' => $order->closed_on?->toDateString(),
            'odometer' => $order->odometer !== null ? (int) $order->odometer : null,
            'puts_in_maintenance' => (bool) $order->puts_in_maintenance,
            'notes' => MaintenanceContractLabel::present($order->notes, $canContracts),
            'next_service_on' => $order->next_service_on?->toDateString(),
            'next_service_odometer' => $order->next_service_odometer !== null
                ? (int) $order->next_service_odometer
                : null,
            'items' => $items,
            'has_oil_change' => collect($items)->contains(
                fn ($label) => is_string($label) && str_contains($label, MaintenanceOilScheduleService::OIL_ITEM)
            ),
            'vehicle' => $order->vehicle ? [
                'id' => (int) $order->vehicle->id,
                'brand' => $order->vehicle->brand,
                'model' => $order->vehicle->model,
                'plate' => $order->vehicle->plate,
                'code' => $order->vehicle->code,
                'odometer' => (int) ($order->vehicle->odometer ?? 0),
            ] : null,
        ];

        return VehicleOwnerVisibility::redactEmbedded($payload, $request->user());
    }

    /**
     * استجابة إنشاء/إغلاق أمر شغل — بنود الخطوط للمخزون/العهدة، بلا totals مالية في JSON.
     * قائمة الصيانة التشغيلية تستخدم presentMaintenance.
     *
     * @return array<string, mixed>
     */
    private function present(WorkOrder $order, Request $request): array
    {
        $order->loadMissing(['vehicle', 'lines']);
        $payload = $this->presentMaintenance($order, $request);
        $canContracts = (bool) $request->user()?->hasPermission('contracts.view');
        $payload['lines'] = $order->lines->map(function ($line) use ($canContracts) {
            return [
                'id' => (int) $line->id,
                'work_order_id' => (int) $line->work_order_id,
                'description' => MaintenanceContractLabel::present($line->description, $canContracts),
                'part_source' => $line->part_source,
                'inventory_item_id' => $line->inventory_item_id !== null ? (int) $line->inventory_item_id : null,
                'quantity' => (int) $line->quantity,
                'unit_price' => (int) $line->unit_price,
            ];
        })->values()->all();

        return VehicleOwnerVisibility::redactEmbedded($payload, $request->user());
    }
}
