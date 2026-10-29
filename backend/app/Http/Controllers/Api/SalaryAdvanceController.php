<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalaryAdvance;
use App\Services\Operations\PayrollService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP adapter over PayrollService so /salary-advances stays compatible
 * with main's salary_advances schema (advanced_on + payroll_line_id).
 */
class SalaryAdvanceController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);

        return response()->json(['data' => $this->service->advancesFor($year, $month)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'advanced_on' => ['required', 'date'],
            'method' => ['nullable', 'in:cash,transfer,card'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'employee_id' => 'الموظف',
            'amount' => 'مبلغ السلفة',
            'advanced_on' => 'تاريخ السلفة',
        ]);

        try {
            $advance = $this->service->recordAdvance($data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $advance, 'message' => 'تم تسجيل السلفة.'], 201);
    }

    public function destroy(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        try {
            $this->service->deleteAdvance($salaryAdvance, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'تم حذف السلفة.']);
    }
}
