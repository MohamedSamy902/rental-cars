<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
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

        $rows = $q->paginate((int) $request->integer('per_page', 100));

        return response()->json([
            'data' => $rows->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
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
