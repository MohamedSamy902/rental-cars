<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\PayrollLine;
use App\Models\PettyCashAccount;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Support\PaginationInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Employee::query()->latest('id');

        if ($search = $request->string('search')->toString()) {
            $q->where(function ($qq) use ($search) {
                $qq->where('name', 'like', "%{$search}%")
                    ->orWhere('job_title', 'like', "%{$search}%");
            });
        }

        if ($status = $request->string('status')->toString()) {
            $q->where('status', $status);
        }

        $perPage = PaginationInput::perPage($request, 100, PaginationInput::MAX_PER_PAGE);
        $page = PaginationInput::page($request);
        $rows = $q->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function show(Employee $employee): JsonResponse
    {
        $user = User::query()
            ->with(['role:id,name,label', 'role.permissions:id,key,label,module'])
            ->where('employee_id', $employee->id)
            ->first();

        $advances = SalaryAdvance::query()
            ->where('employee_id', $employee->id)
            ->whereNull('payroll_line_id')
            ->latest('advanced_on')
            ->limit(50)
            ->get();

        $assignments = EmployeeAssignment::query()
            ->with(['assignedBy:id,name', 'acknowledgedBy:id,name'])
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->limit(50)
            ->get();

        $petty = PettyCashAccount::query()
            ->where('employee_id', $employee->id)
            ->first();

        $payrollHistory = PayrollLine::query()
            ->with(['run:id,year,month,status', 'payouts'])
            ->where('employee_id', $employee->id)
            ->latest('id')
            ->limit(24)
            ->get()
            ->map(function (PayrollLine $line) {
                return [
                    'id' => $line->id,
                    'base_salary' => $line->base_salary,
                    'allowances' => $line->allowances,
                    'deductions' => $line->deductions,
                    'advances' => $line->advances,
                    'net_amount' => $line->net_amount,
                    'paid_amount' => $line->paid_amount,
                    'carried_over' => $line->carried_over,
                    'year' => $line->run?->year,
                    'month' => $line->run?->month,
                    'run_status' => $line->run?->status,
                    'payouts' => $line->payouts,
                ];
            });

        return response()->json([
            'data' => [
                'employee' => $employee,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'is_active' => $user->is_active,
                    'role' => $user->role ? [
                        'id' => $user->role->id,
                        'name' => $user->role->name,
                        'label' => $user->role->label,
                        'permissions' => $user->role->permissions
                            ?->map(fn ($p) => ['id' => $p->id, 'key' => $p->key, 'label' => $p->label, 'module' => $p->module])
                            ->values()
                            ->all() ?? [],
                    ] : null,
                ] : null,
                'advances' => $advances,
                'assignments' => $assignments,
                'petty_cash' => $petty,
                'payroll_history' => $payrollHistory,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_on' => ['nullable', 'date'],
            'base_salary' => ['required', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,ended'],
        ], [], [
            'name' => 'الاسم',
            'job_title' => 'المسمى الوظيفي',
            'hired_on' => 'تاريخ التعيين',
            'base_salary' => 'الراتب الأساسي',
            'status' => 'الحالة',
        ]);

        $employee = Employee::query()->create([
            ...$data,
            'status' => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'data' => $employee,
            'message' => 'تم تسجيل الموظف في الموارد البشرية.',
        ], 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'hired_on' => ['nullable', 'date'],
            'base_salary' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:active,ended'],
        ], [], [
            'name' => 'الاسم',
            'job_title' => 'المسمى الوظيفي',
            'hired_on' => 'تاريخ التعيين',
            'base_salary' => 'الراتب الأساسي',
            'status' => 'الحالة',
        ]);

        $employee->update($data);

        return response()->json([
            'data' => $employee->fresh(),
            'message' => 'تم تحديث بيانات الموظف.',
        ]);
    }
}
