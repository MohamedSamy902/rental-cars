<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkOrder;
use App\Services\Maintenance\WorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = WorkOrder::query()->with(['vehicle', 'lines'])->latest('id');
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }
        if ($vehicleId = $request->integer('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }
        $rows = $q->paginate(50);

        return response()->json([
            'data' => $rows->items(),
            'meta' => ['total' => $rows->total()],
            'summary' => [
                'open' => WorkOrder::query()->whereIn('status', ['open', 'in_progress'])->count(),
                'open_only' => WorkOrder::query()->where('status', 'open')->count(),
                'in_progress' => WorkOrder::query()->where('status', 'in_progress')->count(),
                'closed' => WorkOrder::query()->where('status', 'closed')->count(),
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
            'force_after_resolution' => ['nullable', 'boolean'],
            'labor_cost' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'next_service_on' => ['nullable', 'date'],
            'next_service_odometer' => ['nullable', 'integer', 'min:0'],
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

        try {
            $order = $this->service->open($data, $data['lines'] ?? [], $request->user()?->id);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $order, 'message' => 'تم فتح أمر الشغل.'], 201);
    }

    public function close(WorkOrder $workOrder): JsonResponse
    {
        $order = $this->service->close($workOrder->load('lines'));

        return response()->json(['data' => $order, 'message' => 'تم إغلاق أمر الشغل.']);
    }
}
