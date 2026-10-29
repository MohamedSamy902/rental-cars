<?php

namespace App\Services\Operations;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollLine;
use App\Models\PayrollPayout;
use App\Models\PayrollRun;
use App\Models\SalaryAdvance;
use App\Services\Accounting\AccountingService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payroll cycle: draft → approve → (partial) payout. Advances taken during the month are
 * deducted from that month's line; any unpaid remainder is carried into the next cycle.
 */
class PayrollService
{
    public function __construct(private readonly AccountingService $accounting) {}

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

            $oldLineIds = $run->lines()->pluck('id');
            SalaryAdvance::query()->whereIn('payroll_line_id', $oldLineIds)->update(['payroll_line_id' => null]);
            $run->lines()->delete();

            foreach (Employee::query()->where('status', 'active')->get() as $employee) {
                $line = PayrollLine::query()->create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'base_salary' => (int) $employee->base_salary,
                    'allowances' => 0,
                    'deductions' => 0,
                    'net_amount' => 0,
                ]);
                $this->recalculateLine($line, $run);
            }

            $this->refreshTotal($run);

            return $this->loadRun($run);
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

        return $this->loadRun($run);
    }

    /**
     * @param  array{employee_id: int, amount: int, advanced_on: string, method?: string|null, notes?: string|null}  $data
     */
    public function recordAdvance(array $data, ?int $userId = null): SalaryAdvance
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        if ($employee->status !== 'active') {
            throw new DomainException('لا يمكن تسجيل سلفة لموظف غير نشط.');
        }

        $amount = (int) $data['amount'];
        if ($amount <= 0) {
            throw new DomainException('مبلغ السلفة غير صالح.');
        }

        $on = CarbonImmutable::parse($data['advanced_on']);
        $year = (int) $on->format('Y');
        $month = (int) $on->format('n');

        $run = PayrollRun::query()->where(compact('year', 'month'))->first();
        if ($run?->status === 'approved') {
            throw new DomainException('مسير هذا الشهر معتمد — سجّل السلفة على الشهر التالي.');
        }

        [$from, $to] = $this->monthBounds($year, $month);
        $already = (int) SalaryAdvance::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('advanced_on', [$from, $to])
            ->sum('amount');
        if ($already + $amount > (int) $employee->base_salary) {
            throw new DomainException('إجمالي السلف يتجاوز الراتب الأساسي لهذا الشهر.');
        }

        return DB::transaction(function () use ($employee, $amount, $on, $data, $userId, $run) {
            $advance = SalaryAdvance::query()->create([
                'employee_id' => $employee->id,
                'amount' => $amount,
                'advanced_on' => $on->toDateString(),
                'method' => $data['method'] ?? 'cash',
                'recorded_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($run) {
                $line = $run->lines()->where('employee_id', $employee->id)->first();
                if ($line) {
                    $this->recalculateLine($line, $run);
                    $this->refreshTotal($run);
                }
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'salary_advance_recorded',
                'record_type' => SalaryAdvance::class,
                'record_id' => $advance->id,
                'after' => ['employee_id' => $employee->id, 'amount' => $amount, 'advanced_on' => $on->toDateString()],
            ]);

            return $advance->fresh('employee:id,name');
        });
    }

    public function deleteAdvance(SalaryAdvance $advance, ?int $userId = null): void
    {
        $line = $advance->line()->with('run')->first();
        if ($line?->run?->status === 'approved') {
            throw new DomainException('السلفة مخصومة في مسير معتمد ولا يمكن حذفها.');
        }

        DB::transaction(function () use ($advance, $line, $userId) {
            $snapshot = $advance->only(['employee_id', 'amount', 'advanced_on']);
            $advance->delete();

            if ($line) {
                $this->recalculateLine($line, $line->run);
                $this->refreshTotal($line->run);
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'salary_advance_deleted',
                'record_type' => SalaryAdvance::class,
                'record_id' => $advance->id,
                'before' => $snapshot,
            ]);
        });
    }

    /**
     * Pay all or part of an approved line; the unpaid remainder becomes `carried_over`
     * and is added to the employee's next payroll draft as `carried_in`.
     *
     * @param  array{amount: int, paid_on?: string|null, method?: string|null, notes?: string|null}  $data
     */
    public function payout(PayrollLine $line, array $data, ?int $userId = null): PayrollLine
    {
        $run = $line->run;
        if ($run->status !== 'approved') {
            throw new DomainException('اعتمد المسير قبل الصرف.');
        }

        $amount = (int) $data['amount'];
        if ($amount <= 0 || $amount > $line->remaining()) {
            throw new DomainException('المبلغ يتجاوز المتبقي المستحق لهذا الموظف.');
        }

        if ($this->laterRuns($run)->where('status', 'approved')->exists()) {
            throw new DomainException('تم ترحيل المتبقي إلى مسير لاحق معتمد — اصرفه من هناك.');
        }

        $paidOn = CarbonImmutable::parse($data['paid_on'] ?? now()->toDateString());
        $this->accounting->assertMonthOpen((int) $paidOn->format('Y'), (int) $paidOn->format('n'));

        return DB::transaction(function () use ($line, $run, $amount, $paidOn, $data, $userId) {
            PayrollPayout::query()->create([
                'payroll_line_id' => $line->id,
                'amount' => $amount,
                'paid_on' => $paidOn->toDateString(),
                'method' => $data['method'] ?? 'cash',
                'paid_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $line->paid_amount += $amount;
            $line->carried_over = $line->remaining();
            $line->save();

            $nextDraft = $this->laterRuns($run)->where('status', 'draft')->get();
            foreach ($nextDraft as $draft) {
                $nextLine = $draft->lines()->where('employee_id', $line->employee_id)->first();
                if ($nextLine) {
                    $this->recalculateLine($nextLine, $draft);
                    $this->refreshTotal($draft);
                }
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'payroll_payout',
                'record_type' => PayrollLine::class,
                'record_id' => $line->id,
                'after' => [
                    'amount' => $amount,
                    'paid_amount' => $line->paid_amount,
                    'carried_over' => $line->carried_over,
                ],
            ]);

            return $line->fresh(['employee', 'payouts', 'salaryAdvances']);
        });
    }

    /**
     * @return Collection<int, SalaryAdvance>
     */
    public function advancesFor(int $year, int $month): Collection
    {
        [$from, $to] = $this->monthBounds($year, $month);

        return SalaryAdvance::query()
            ->with('employee:id,name')
            ->whereBetween('advanced_on', [$from, $to])
            ->latest('advanced_on')
            ->latest('id')
            ->get();
    }

    public function loadRun(PayrollRun $run): PayrollRun
    {
        return $run->fresh(['lines.employee', 'lines.payouts', 'lines.salaryAdvances']);
    }

    private function recalculateLine(PayrollLine $line, PayrollRun $run): void
    {
        [$from, $to] = $this->monthBounds((int) $run->year, (int) $run->month);

        $advances = SalaryAdvance::query()
            ->where('employee_id', $line->employee_id)
            ->whereBetween('advanced_on', [$from, $to])
            ->where(fn ($q) => $q->whereNull('payroll_line_id')->orWhere('payroll_line_id', $line->id))
            ->get();
        SalaryAdvance::query()->whereKey($advances->modelKeys())->update(['payroll_line_id' => $line->id]);

        $advanceTotal = (int) $advances->sum('amount');
        $carriedIn = $this->previousCarry((int) $line->employee_id, (int) $run->year, (int) $run->month);
        $gross = (int) $line->base_salary + (int) $line->allowances + $carriedIn;
        $net = max(0, $gross - (int) $line->deductions - $advanceTotal);

        $line->fill([
            'carried_in' => $carriedIn,
            'advances' => $advanceTotal,
            'net_amount' => $net,
            'carried_over' => $line->paid_amount > 0 ? max(0, $net - (int) $line->paid_amount) : 0,
        ])->save();
    }

    private function previousCarry(int $employeeId, int $year, int $month): int
    {
        $prev = PayrollLine::query()
            ->select('payroll_lines.*')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
            ->where('payroll_lines.employee_id', $employeeId)
            ->where('payroll_runs.status', 'approved')
            ->whereRaw('(payroll_runs.year * 12 + payroll_runs.month) < ?', [$year * 12 + $month])
            ->orderByDesc('payroll_runs.year')
            ->orderByDesc('payroll_runs.month')
            ->first();

        return (int) ($prev?->carried_over ?? 0);
    }

    private function refreshTotal(PayrollRun $run): void
    {
        $run->update(['total_amount' => (int) $run->lines()->sum('net_amount')]);
    }

    /**
     * @return Builder<PayrollRun>
     */
    private function laterRuns(PayrollRun $run)
    {
        return PayrollRun::query()->whereRaw('(year * 12 + month) > ?', [$run->year * 12 + $run->month]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthBounds(int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
