<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PettyCashAccount;
use App\Services\Customers\CustomerCreditService;
use Carbon\Carbon;

class ReportQueryService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly CustomerCreditService $credit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function trialBalance(?Carbon $from = null, ?Carbon $to = null): array
    {
        $this->accounting->ensureChartOfAccounts();
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
                $income[] = [...$row, 'amount' => $amount];
                $incomeTotal += $amount;
            }
            if ($row['type'] === 'expense') {
                $amount = (int) $row['debit'] - (int) $row['credit'];
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
            $q->whereDate('date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('date', '<=', $to);
        }
        $labels = [
            'cash' => 'نقدي',
            'transfer' => 'تحويل',
            'card' => 'بطاقة',
            'petty_cash' => 'عهدة',
        ];
        $grouped = $q->get()->groupBy('method');
        $out = [];
        foreach ($labels as $method => $label) {
            $rows = $grouped->get($method, collect());
            $in = (int) $rows->where('direction', 'in')->sum('amount');
            $outAmt = (int) $rows->where('direction', 'out')->sum('amount');
            $out[] = [
                'method' => $method,
                'label' => $label,
                'in' => $in,
                'out' => $outAmt,
                'net' => $in - $outAmt,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function customerStatement(Customer $customer): array
    {
        $arDebit = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '1200'))
            ->whereHas('entry', fn ($q) => $q->where('memo', 'like', '%'.$customer->full_name.'%')
                ->orWhere('source_id', $customer->id))
            ->sum('debit');
        // Prefer contract-linked payments + period totals
        $contracts = $customer->contracts()->with('deposit')->latest('id')->limit(50)->get();
        $contractDebt = 0;
        $paid = 0;
        foreach ($contracts as $c) {
            $total = (int) ($c->final_total ?: $c->period_rent ?: 0);
            $contractDebt += $total;
            $paid += (int) Payment::query()
                ->where('link_type', 'contract')
                ->where('link_id', $c->id)
                ->where('direction', 'in')
                ->sum('amount');
        }

        return [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
                'credit_balance' => (int) $customer->credit_balance,
            ],
            'contracts_total' => $contractDebt,
            'payments_in' => $paid,
            'open_receivable' => max(0, $contractDebt - $paid),
            'credit_balance' => (int) $customer->credit_balance,
            'credit_ledger' => $this->credit->ledger($customer),
            'contracts' => $contracts->map(fn ($c) => [
                'id' => $c->id,
                'number' => $c->number,
                'status' => $c->status,
                'final_total' => (int) ($c->final_total ?: 0),
                'period_rent' => (int) ($c->period_rent ?: 0),
            ])->all(),
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
