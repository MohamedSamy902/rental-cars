<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAssignment;
use App\Services\Operations\EmployeeAssignmentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAssignmentController extends Controller
{
    public function __construct(private readonly EmployeeAssignmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        $q = EmployeeAssignment::query()->with(['employee:id,name', 'assignedBy:id,name', 'acknowledgedBy:id,name'])->latest('id');
        if ($employeeId = $request->integer('employee_id')) {
            $q->where('employee_id', $employeeId);
        }
        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->limit(200)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'title' => ['required', 'string', 'max:200'],
            'assignable_type' => ['nullable', 'string', 'max:80'],
            'assignable_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'employee_id' => 'الموظف',
            'title' => 'عنوان التسليم',
        ]);

        $row = $this->service->assign($data, $request->user()?->id);

        return response()->json([
            'data' => $row->load(['employee:id,name', 'assignedBy:id,name']),
            'message' => 'تم تسليم العهدة/الأصل للموظف — بانتظار تأكيد استلام الأدمن.',
        ], 201);
    }

    public function acknowledgeReceipt(Request $request, EmployeeAssignment $assignment): JsonResponse
    {
        try {
            $row = $this->service->acknowledgeReceipt($assignment, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $row,
            'message' => 'تم تأكيد استلام الأدمن لتسليم العهدة/الأصل.',
        ]);
    }
}
