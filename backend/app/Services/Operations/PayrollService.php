<?php

namespace App\Services\Operations;

use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollLinePayout;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use Carbon\Carbon;
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

            $prev = Carbon::create($year, $month, 1)->subMonth();
            $carriedInByEmployee = PayrollLine::query()
                ->whereHas('run', fn ($q) => $q->where('year', $prev->year)->where('month', $prev->month)->where('status', 'approved'))
                ->get()
                ->groupBy('employee_id')
                ->map(fn ($rows) => (int) $rows->sum('carried_over'));

            $advancesByEmployee = SalaryAdvance::query()
                ->where('year', $year)
                ->where('month', $month)
                ->get()
                ->groupBy('employee_id')
                ->map(fn ($rows) => (int) $rows->sum('amount'));

            $total = 0;
            foreach (Employee::query()->where('status', 'active')->get() as $employee) {
                $base = (int) $employee->base_salary;
                $carriedIn = (int) ($carriedInByEmployee[$employee->id] ?? 0);
                $advances = (int) ($advancesByEmployee[$employee->id] ?? 0);
                $net = max(0, $base + $carriedIn - $advances);
                PayrollLine::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => $base,
                    'allowances' => 0,
                    'deductions' => $advances,
                    'carried_in' => $carriedIn,
                    'advances' => $advances,
                    'net_amount' => $net,
                    'paid_amount' => 0,
                    'carried_over' => 0,
                    'notes' => $advances > 0 || $carriedIn > 0
                        ? trim(($carriedIn ? "مرحّل داخل {$carriedIn}" : '').($advances ? " · خصم سلف {$advances}" : ''))
                        : null,
                ]);
                $total += $net;
            }
            $run->update(['total_amount' => $total]);

            return $run->fresh(['lines.employee', 'lines.payouts']);
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

        return $run->fresh(['lines.employee', 'lines.payouts']);
    }

    /**
     * صرف جزئي أو كامل لبند مسير — أي متبقّي يُرحَّل تلقائياً.
     *
     * @param  array{amount:int,paid_on:string,method?:string,notes?:string|null}  $data
     */
    public function payout(PayrollLine $line, array $data, ?int $userId = null): PayrollLine
    {
        $line->loadMissing('run');
        if ($line->run?->status !== 'approved') {
            throw new DomainException('لا يمكن الصرف إلا بعد اعتماد المسير.');
        }

        $amount = (int) $data['amount'];
        if ($amount < 1) {
            throw new DomainException('مبلغ الصرف يجب أن يكون أكبر من صفر.');
        }

        $remaining = $line->remaining();
        if ($remaining < 1) {
            throw new DomainException('لا متبقي للصرف على هذا البند.');
        }
        if ($amount > $remaining) {
            throw new DomainException("المبلغ يتجاوز المتبقي ({$remaining}).");
        }

        return DB::transaction(function () use ($line, $data, $amount, $userId) {
            PayrollLinePayout::query()->create([
                'payroll_line_id' => $line->id,
                'amount' => $amount,
                'paid_on' => $data['paid_on'],
                'method' => $data['method'] ?? 'cash',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $paid = (int) $line->paid_amount + $amount;
            $carriedOver = max(0, (int) $line->net_amount - $paid);
            $line->fill([
                'paid_amount' => $paid,
                'carried_over' => $carriedOver,
            ])->save();

            return $line->fresh(['employee', 'payouts', 'run']);
        });
    }
}
