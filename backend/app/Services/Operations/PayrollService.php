<?php

namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use DomainException;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function draft(int $year, int $month, ?int $userId = null, ?string $notes = null): PayrollRun
    {
        if (PayrollRun::query()->where(compact('year', 'month'))->where('status', 'approved')->exists()) {
            throw new DomainException('مسير هذا الشهر معتمد مسبقاً.');
        }

        return DB::transaction(function () use ($year, $month, $userId, $notes) {
            $run = PayrollRun::query()->updateOrCreate(
                compact('year', 'month'),
                [
                    'status' => 'draft',
                    'created_by' => $userId,
                    'approved_by' => null,
                    'approved_at' => null,
                    'notes' => $notes,
                    'total_amount' => 0,
                ],
            );
            $run->lines()->delete();

            $advancesByEmployee = SalaryAdvance::query()
                ->where('year', $year)
                ->where('month', $month)
                ->get()
                ->groupBy('employee_id')
                ->map(fn ($rows) => (int) $rows->sum('amount'));

            $total = 0;
            foreach (Employee::query()->where('status', 'active')->get() as $employee) {
                $base = (int) $employee->base_salary;
                $advanceDeduction = (int) ($advancesByEmployee[$employee->id] ?? 0);
                $net = max(0, $base - $advanceDeduction);
                PayrollLine::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => $base,
                    'allowances' => 0,
                    'deductions' => $advanceDeduction,
                    'net_amount' => $net,
                    'notes' => $advanceDeduction > 0 ? "خصم سلف {$advanceDeduction}" : null,
                ]);
                $total += $net;
            }
            $run->update(['total_amount' => $total]);

            return $run->fresh('lines.employee');
        });
    }

    public function approve(PayrollRun $run, ?int $userId = null): PayrollRun
    {
        if ($run->status === 'approved') {
            throw new DomainException('المسير معتمد مسبقاً.');
        }
        if ($run->lines()->count() === 0) {
            throw new DomainException('لا بنود في المسير.');
        }

        $run->fill([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ])->save();

        return $run->fresh('lines.employee');
    }
}
