<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->format('Y');
        $month = $request->integer('month') ?: (int) now()->format('n');

        $rows = SalaryAdvance::query()
            ->with('employee:id,name')
            ->where('year', $year)
            ->where('month', $month)
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'advanced_on' => ['required', 'date'],
            'method' => ['nullable', 'in:cash,transfer,card'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'employee_id' => 'الموظف',
            'amount' => 'المبلغ',
            'advanced_on' => 'التاريخ',
        ]);

        Employee::query()->findOrFail($data['employee_id']);
        $on = Carbon::parse($data['advanced_on']);

        $row = SalaryAdvance::query()->create([
            'employee_id' => (int) $data['employee_id'],
            'amount' => (int) $data['amount'],
            'advanced_on' => $on->toDateString(),
            'method' => $data['method'] ?? 'cash',
            'year' => (int) $on->format('Y'),
            'month' => (int) $on->format('n'),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'data' => $row->load('employee:id,name'),
            'message' => 'تم تسجيل السلفة.',
        ], 201);
    }

    public function destroy(SalaryAdvance $salaryAdvance): JsonResponse
    {
        $salaryAdvance->delete();

        return response()->json(['message' => 'تم حذف السلفة.']);
    }
}
