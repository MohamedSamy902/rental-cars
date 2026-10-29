<?php

namespace App\Services\Accounting;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\Payment;
use App\Models\PayrollPayout;
use App\Models\User;
use App\Models\Violation;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Read model for a single accounting month: every money movement that landed in it,
 * grouped the way the accountant closes the month, plus the lock/reopen audit trail.
 */
class MonthLedgerService
{
    /** @var array<string, string> */
    private const FEE_ACCOUNTS = [
        '4100' => 'كيلومترات زيادة',
        '4200' => 'أضرار',
        '4300' => 'رسوم تشغيل',
    ];

    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $year, int $month): array
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new DomainException('الشهر المطلوب غير صالح.');
        }

        $this->accounting->ensureChartOfAccounts();
        $start = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $end = $start->endOfMonth()->endOfDay();
        $range = [$start, $end];
        $dateRange = [$start->toDateString(), $end->toDateString()];

        $rents = $this->journalRows(['4000'], $dateRange);
        $fees = $this->journalRows(array_keys(self::FEE_ACCOUNTS), $dateRange);

        $expenses = Expense::query()
            ->with('vehicle:id,brand,model,plate')
            ->whereBetween('spent_on', $dateRange)
            ->orderBy('spent_on')
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'date' => $e->spent_on?->toDateString(),
                'title' => $e->title,
                'category' => $e->category,
                'method' => $e->from_petty_cash ? 'petty_cash' : $e->payment_method,
                'vehicle' => $e->vehicle ? trim("{$e->vehicle->brand} {$e->vehicle->model} · {$e->vehicle->plate}") : null,
                'amount' => (int) $e->amount,
            ])->values();

        $depositsReceived = Deposit::query()
            ->with('contract:id,number,customer_id', 'contract.customer:id,full_name')
            ->whereBetween('received_at', $range)
            ->orderBy('received_at')
            ->get()
            ->map(fn (Deposit $d) => [
                'id' => $d->id,
                'date' => $d->received_at?->toIso8601String(),
                'contract_id' => $d->contract_id,
                'contract' => $d->contract?->number,
                'customer' => $d->contract?->customer?->full_name,
                'receipt' => $d->receipt_number,
                'method' => $d->collection_method,
                'amount' => (int) $d->original_amount,
            ])->values();

        $depositsReturned = Deposit::query()
            ->with('contract:id,number,customer_id', 'contract.customer:id,full_name')
            ->whereBetween('returned_at', $range)
            ->orderBy('returned_at')
            ->get()
            ->map(fn (Deposit $d) => [
                'id' => $d->id,
                'date' => $d->returned_at?->toIso8601String(),
                'contract_id' => $d->contract_id,
                'contract' => $d->contract?->number,
                'customer' => $d->contract?->customer?->full_name,
                'receipt' => $d->return_receipt_number,
                'method' => $d->return_method,
                'amount' => $d->remaining(),
            ])->values();

        $deductions = DepositDeduction::query()
            ->whereBetween('deducted_at', $range)
            ->orderBy('deducted_at')
            ->get();
        $deductionDeposits = Deposit::query()
            ->with('contract:id,number,customer_id', 'contract.customer:id,full_name')
            ->whereKey($deductions->pluck('deposit_id')->unique()->all())
            ->get()
            ->keyBy('id');
        $depositDeductions = $deductions->map(function (DepositDeduction $x) use ($deductionDeposits) {
            $deposit = $deductionDeposits->get($x->deposit_id);

            return [
                'id' => $x->id,
                'date' => $x->deducted_at?->toIso8601String(),
                'contract_id' => $deposit?->contract_id,
                'contract' => $deposit?->contract?->number,
                'customer' => $deposit?->contract?->customer?->full_name,
                'reason' => $x->reason,
                'amount' => (int) $x->amount,
            ];
        })->values();

        $payments = Payment::query()->whereBetween('paid_at', $range)->orderBy('paid_at')->get();
        $mapPayment = fn (Payment $p) => [
            'id' => $p->id,
            'date' => $p->paid_at?->toIso8601String(),
            'method' => $p->method,
            'reference' => $p->reference,
            'link_type' => $p->link_type,
            'link_id' => $p->link_id,
            'notes' => $p->notes,
            'amount' => (int) $p->amount,
        ];
        $paymentsIn = $payments->where('direction', 'in')->map($mapPayment)->values();
        $clientPayouts = $payments->where('direction', 'out')->map($mapPayment)->values();

        $payroll = PayrollPayout::query()
            ->with('line.employee:id,name')
            ->whereBetween('paid_on', $dateRange)
            ->orderBy('paid_on')
            ->get()
            ->map(fn (PayrollPayout $p) => [
                'id' => $p->id,
                'date' => $p->paid_on?->toDateString(),
                'employee' => $p->line?->employee?->name,
                'method' => $p->method,
                'amount' => (int) $p->amount,
            ])->values();

        $violations = Violation::query()
            ->with('vehicle:id,plate', 'customer:id,full_name')
            ->whereBetween('occurred_at', $range)
            ->orderBy('occurred_at')
            ->get()
            ->map(fn (Violation $v) => [
                'id' => $v->id,
                'date' => $v->occurred_at?->toIso8601String(),
                'plate' => $v->vehicle?->plate,
                'customer' => $v->customer?->full_name,
                'status' => $v->status,
                'amount' => (int) $v->amount,
            ])->values();

        $rentTotal = (int) $rents->sum('amount');
        $feesTotal = (int) $fees->sum('amount');
        $expensesTotal = (int) $expenses->sum('amount');
        $payrollTotal = (int) $payroll->sum('amount');

        $lock = MonthLock::query()->where(compact('year', 'month'))->first();

        return [
            'year' => $year,
            'month' => $month,
            'label' => $start->translatedFormat('F Y'),
            'lock' => [
                'locked' => $lock?->isLocked() ?? false,
                'locked_at' => $lock?->locked_at?->toIso8601String(),
                'locked_by' => $lock?->locked_by ? User::query()->whereKey($lock->locked_by)->value('name') : null,
                'reopen_reason' => $lock?->reopen_reason,
            ],
            'summary' => [
                'rent_revenue' => $rentTotal,
                'fees_revenue' => $feesTotal,
                'expenses' => $expensesTotal,
                'payroll_paid' => $payrollTotal,
                'net_operating' => $rentTotal + $feesTotal - $expensesTotal - $payrollTotal,
                'deposits_received' => (int) $depositsReceived->sum('amount'),
                'deposits_returned' => (int) $depositsReturned->sum('amount'),
                'deposit_deductions' => (int) $depositDeductions->sum('amount'),
                'payments_in' => (int) $paymentsIn->sum('amount'),
                'client_payouts' => (int) $clientPayouts->sum('amount'),
                'violations_tracked' => (int) $violations->sum('amount'),
                'completed_contracts' => Contract::query()
                    ->where('status', 'completed')
                    ->whereBetween('actual_ends_at', $range)
                    ->count(),
            ],
            'rents' => $rents,
            'fees' => $fees,
            'expenses' => $expenses,
            'deposits' => [
                'received' => $depositsReceived,
                'returned' => $depositsReturned,
                'deductions' => $depositDeductions,
            ],
            'payments_in' => $paymentsIn,
            'client_payouts' => $clientPayouts,
            'payroll' => $payroll,
            'violations' => $violations,
            'audit' => $this->auditTrail($lock),
        ];
    }

    /**
     * Net credit per journal line on the given income accounts, tied back to its contract.
     *
     * @param  list<string>  $codes
     * @param  array{0: string, 1: string}  $dateRange
     * @return Collection<int, array<string, mixed>>
     */
    private function journalRows(array $codes, array $dateRange): Collection
    {
        $lines = JournalLine::query()
            ->with(['entry', 'account'])
            ->whereHas('account', fn ($q) => $q->whereIn('code', $codes))
            ->whereHas('entry', fn ($q) => $q->whereBetween('entry_date', $dateRange))
            ->get();

        $contractIds = $lines
            ->map(fn (JournalLine $l) => str_starts_with((string) $l->entry?->source, 'contract_close:')
                ? (int) substr((string) $l->entry->source, strlen('contract_close:'))
                : null)
            ->filter()
            ->unique()
            ->all();
        $contracts = Contract::query()
            ->with('customer:id,full_name', 'vehicle:id,brand,model,plate')
            ->whereKey($contractIds)
            ->get()
            ->keyBy('id');

        return $lines
            ->map(function (JournalLine $l) use ($contracts) {
                $source = (string) $l->entry?->source;
                $contractId = str_starts_with($source, 'contract_close:') ? (int) substr($source, strlen('contract_close:')) : null;
                $contract = $contractId ? $contracts->get($contractId) : null;
                $code = (string) $l->account?->code;

                return [
                    'id' => $l->id,
                    'date' => $l->entry?->entry_date?->toDateString(),
                    'narration' => $l->entry?->narration,
                    'account_code' => $code,
                    'kind' => self::FEE_ACCOUNTS[$code] ?? 'إيجار',
                    'contract_id' => $contractId,
                    'contract' => $contract?->number,
                    'customer' => $contract?->customer?->full_name,
                    'vehicle' => $contract?->vehicle ? "{$contract->vehicle->brand} {$contract->vehicle->model} · {$contract->vehicle->plate}" : null,
                    'amount' => (int) $l->credit - (int) $l->debit,
                ];
            })
            ->filter(fn (array $row) => $row['amount'] !== 0)
            ->sortBy('date')
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function auditTrail(?MonthLock $lock): Collection
    {
        if (! $lock) {
            return collect();
        }

        $logs = AuditLog::query()
            ->where('record_type', MonthLock::class)
            ->where('record_id', $lock->id)
            ->latest('id')
            ->get();
        $users = User::query()->whereKey($logs->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return $logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'at' => $log->created_at?->toIso8601String(),
            'user' => $log->user_id ? $users->get($log->user_id) : null,
            'reason' => $log->after['reason'] ?? null,
        ])->values();
    }
}
