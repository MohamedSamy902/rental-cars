<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Services\Operations\ExpenseService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = Expense::query()->with(['vehicle', 'employee', 'workOrder', 'recorder:id,name'])->latest('id');
        if ($vehicleId = $request->integer('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }
        if ($scope = $request->string('scope')->toString()) {
            if ($scope === 'general') {
                $q->whereNull('vehicle_id');
            } elseif ($scope === 'vehicle') {
                $q->whereNotNull('vehicle_id');
            }
        }

        $rows = $q->limit(100)->get()->map(function (Expense $e) {
            $arr = $e->toArray();
            $arr['recorded_by_name'] = $e->recorder?->name;
            $arr['scope'] = $e->vehicle_id ? 'vehicle' : 'general';

            return $arr;
        });

        return response()->json([
            'data' => $rows,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $row = $this->service->create($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $row, 'message' => 'تم تسجيل المصروف.'], 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $data = $this->validated($request);

        try {
            $row = $this->service->update($expense, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $row, 'message' => 'تم تحديث المصروف.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1'],
            'spent_on' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'work_order_id' => ['nullable', 'exists:work_orders,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'from_petty_cash' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'title' => 'العنوان',
            'amount' => 'المبلغ',
            'spent_on' => 'تاريخ الصرف',
            'vehicle_id' => 'العربية',
            'work_order_id' => 'أمر الشغل',
            'employee_id' => 'موظف العهدة',
        ]);
    }
}
