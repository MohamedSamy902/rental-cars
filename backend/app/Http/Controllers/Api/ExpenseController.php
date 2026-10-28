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
        $q = Expense::query()->with(['vehicle', 'employee'])->latest('id');
        if ($vehicleId = $request->integer('vehicle_id')) {
            $q->where('vehicle_id', $vehicleId);
        }

        return response()->json([
            'data' => $q->limit(100)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'integer', 'min:1'],
            'spent_on' => ['required', 'date'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'employee_id' => ['nullable', 'exists:employees,id'],
            'from_petty_cash' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'title' => 'العنوان',
            'amount' => 'المبلغ',
            'spent_on' => 'تاريخ الصرف',
        ]);

        try {
            $row = $this->service->create($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $row, 'message' => 'تم تسجيل المصروف.'], 201);
    }
}
