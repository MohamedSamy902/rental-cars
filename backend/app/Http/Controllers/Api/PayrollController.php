<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Services\Operations\PayrollService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => PayrollRun::query()
                ->with(['lines.employee', 'lines.payouts', 'lines.salaryAdvances'])
                ->latest('year')
                ->latest('month')
                ->limit(24)
                ->get(),
        ]);
    }

    public function draft(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'notes' => ['nullable', 'string'],
        ], [], ['year' => 'السنة', 'month' => 'الشهر']);

        try {
            $run = $this->service->draft((int) $data['year'], (int) $data['month'], $request->user()?->id, $data['notes'] ?? null);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run, 'message' => 'تم إعداد كشف رواتب الشهر.'], 201);
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        try {
            $run = $this->service->approve($payrollRun, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $run, 'message' => 'تم اعتماد كشف الرواتب.']);
    }

    public function payout(Request $request, PayrollLine $payrollLine): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_on' => ['nullable', 'date'],
            'method' => ['nullable', 'in:cash,transfer,card'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['amount' => 'المبلغ المصروف', 'paid_on' => 'تاريخ الصرف', 'method' => 'طريقة الصرف']);

        try {
            $line = $this->service->payout($payrollLine, $data, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $line->carried_over > 0 ? 'تم الصرف الجزئي وترحيل المتبقي لكشف الرواتب التالي.' : 'تم صرف الراتب بالكامل.';

        return response()->json(['data' => $line, 'message' => $message]);
    }

    public function advances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);
        $month = (int) ($data['month'] ?? now()->month);

        return response()->json(['data' => $this->service->advancesFor($year, $month)]);
    }

    public function storeAdvance(Request $request): JsonResponse
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

    public function destroyAdvance(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        try {
            $this->service->deleteAdvance($salaryAdvance, $request->user()?->id);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'تم حذف السلفة.']);
    }
}
