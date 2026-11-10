<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PettyCashAccount;
use App\Services\Contracts\ContractLedgerMath;
use App\Services\Customers\CustomerCreditService;
use Carbon\Carbon;

class ReportQueryService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly CustomerCreditService $credit,
        private readonly PaymentMethodNormalizer $methods,
        private readonly ContractLedgerMath $ledgerMath,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function trialBalance(?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->accounting->ensureChartOfAccounts();
        // رحّل إثبات الإيجار لآخر ١٢ شهراً حتى لا يظهر الميزان/الدخل فارغاً بلا قيود
        $sync = [];
        for ($i = 0; $i < 12; $i++) {
            $dt = now()->startOfMonth()->subMonths($i);
            $sync[] = [(int) $dt->format('Y'), (int) $dt->format('n')];
        }
        $this->accounting->syncRentRecognition($sync);

        $from = $from?->copy()->startOfDay();
        $to = $to?->copy()->endOfDay();

        return Account::query()->orderBy('code')->get()->map(function (Account $account) use ($from, $to) {
            $q = JournalLine::query()->where('account_id', $account->id);
            if ($from || $to) {
                $q->whereHas('entry', function ($eq) use ($from, $to) {
                    if ($from) {
                        $eq->where('entry_date', '>=', $from);
                    }
                    if ($to) {
                        $eq->where('entry_date', '<=', $to);
                    }
                });
            }
            $debit = (int) (clone $q)->sum('debit');
            $credit = (int) (clone $q)->sum('credit');

            return [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $debit - $credit,
            ];
        })->all();
    }

    /**
     * @return array{from:string|null,to:string|null,income:list<array<string,mixed>>,expenses:list<array<string,mixed>>,net:int}
     */
    public function incomeStatement(?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = $this->trialBalance($from, $to);
        $income = [];
        $expenses = [];
        $incomeTotal = 0;
        $expenseTotal = 0;
        foreach ($rows as $row) {
            if ($row['type'] === 'income') {
                $amount = (int) $row['credit'] - (int) $row['debit'];
                if ($amount === 0) {
                    continue;
                }
                $income[] = [...$row, 'amount' => $amount];
                $incomeTotal += $amount;
            }
            if ($row['type'] === 'expense') {
                $amount = (int) $row['debit'] - (int) $row['credit'];
                if ($amount === 0) {
                    continue;
                }
                $expenses[] = [...$row, 'amount' => $amount];
                $expenseTotal += $amount;
            }
        }

        return [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'income' => $income,
            'expenses' => $expenses,
            'income_total' => $incomeTotal,
            'expense_total' => $expenseTotal,
            'net' => $incomeTotal - $expenseTotal,
        ];
    }

    /**
     * @return list<array{method:string,label:string,in:int,out:int,net:int}>
     */
    public function paymentMethodBalances(?Carbon $from = null, ?Carbon $to = null): array
    {
        $q = Payment::query();
        if ($from) {
            $q->whereDate('paid_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('paid_at', '<=', $to);
        }
        $buckets = [];
        foreach ($this->methods->reportOrder() as $method) {
            $buckets[$method] = ['in' => 0, 'out' => 0];
        }
        foreach ($q->get() as $payment) {
            $canonical = $this->methods->canonical((string) $payment->method);
            if (! isset($buckets[$canonical])) {
                $buckets[$canonical] = ['in' => 0, 'out' => 0];
            }
            if ($payment->direction === 'in') {
                $buckets[$canonical]['in'] += (int) $payment->amount;
            } else {
                $buckets[$canonical]['out'] += (int) $payment->amount;
            }
        }

        $out = [];
        foreach ($buckets as $method => $sums) {
            if ($sums['in'] === 0 && $sums['out'] === 0 && ! in_array($method, ['cash', 'transfer', 'card', 'petty_cash'], true)) {
                continue;
            }
            $out[] = [
                'method' => $method,
                'label' => $this->methods->label($method),
                'in' => $sums['in'],
                'out' => $sums['out'],
                'net' => $sums['in'] - $sums['out'],
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function customerStatement(Customer $customer): array
    {
        // AR hint from journal lines tagged to this customer (schema has customer_id on lines;
        // entries use narration/source — never memo/source_id).
        $arDebit = (int) JournalLine::query()
            ->where('customer_id', $customer->id)
            ->whereHas('account', fn ($q) => $q->where('code', '1200'))
            ->sum('debit');

        if ($arDebit === 0 && filled($customer->full_name)) {
            $arDebit = (int) JournalLine::query()
                ->whereHas('account', fn ($q) => $q->where('code', '1200'))
                ->whereHas('entry', function ($q) use ($customer) {
                    $q->where('narration', 'like', '%'.$customer->full_name.'%');
                })
                ->sum('debit');
        }

        // Prefer contract-linked payments + period totals
        $contracts = $customer->contracts()
            ->with(['deposit', 'vehicle:id,brand,model,plate'])
            ->latest('id')
            ->limit(50)
            ->get();

        $contractDebt = 0;
        $paidTotal = 0;
        $rows = [];
        foreach ($contracts as $c) {
            $ledger = $this->ledgerMath->forContract($c);
            $total = $ledger['total'];
            $netPaid = max(0, $ledger['paid']);
            $contractDebt += $total;
            $paidTotal += $netPaid;
            $rows[] = [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toDateString(),
                'planned_ends_at' => $c->planned_ends_at?->toDateString(),
                'actual_ends_at' => $c->actual_ends_at?->toDateString(),
                'final_total' => (int) ($c->final_total ?: 0),
                'period_rent' => (int) ($c->period_rent ?: 0),
                'total' => $total,
                'paid' => $netPaid,
                'remaining' => $ledger['balance'],
                'vehicle' => $c->vehicle ? [
                    'id' => $c->vehicle->id,
                    'brand' => $c->vehicle->brand,
                    'model' => $c->vehicle->model,
                    'plate' => $c->vehicle->plate,
                    'label' => trim(($c->vehicle->brand ?? '').' '.($c->vehicle->model ?? '')).' · '.($c->vehicle->plate ?? ''),
                ] : null,
            ];
        }

        return [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
                'credit_balance' => (int) $customer->credit_balance,
            ],
            'contracts_total' => $contractDebt,
            'payments_in' => $paidTotal,
            'open_receivable' => max(0, $contractDebt - $paidTotal),
            'credit_balance' => (int) $customer->credit_balance,
            'credit_ledger' => $this->credit->ledger($customer),
            'contracts' => $rows,
            'journal_ar_hint' => $arDebit,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pettyCashBalances(): array
    {
        return PettyCashAccount::query()->with('employee:id,name')->get()->map(fn ($a) => [
            'id' => $a->id,
            'employee' => $a->employee?->name,
            'limit_amount' => (int) $a->limit_amount,
            'balance' => (int) $a->balance,
            'is_active' => (bool) $a->is_active,
        ])->all();
    }
}
