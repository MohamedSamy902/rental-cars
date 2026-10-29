<?php

namespace App\Services\Customers;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Services\Accounting\AccountingService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CustomerCreditService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * @param  array{reason: string, contract_id?: int|null, payment_id?: int|null, notes?: string|null}  $meta
     */
    public function credit(Customer $customer, int $amount, array $meta, ?int $userId = null): CustomerCreditMovement
    {
        if ($amount <= 0) {
            throw new DomainException('مبلغ الرصيد الدائن غير صالح.');
        }

        return DB::transaction(function () use ($customer, $amount, $meta, $userId) {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $locked->credit_balance = (int) $locked->credit_balance + $amount;
            $locked->save();

            return CustomerCreditMovement::query()->create([
                'customer_id' => $locked->id,
                'direction' => 'credit',
                'amount' => $amount,
                'reason' => $meta['reason'],
                'contract_id' => $meta['contract_id'] ?? null,
                'payment_id' => $meta['payment_id'] ?? null,
                'recorded_by' => $userId,
                'moved_at' => now(),
                'notes' => $meta['notes'] ?? null,
            ]);
        });
    }

    /**
     * Apply customer credit toward a contract — records an inbound payment (method=credit)
     * and debits the ledger balance.
     */
    public function applyToContract(Customer $customer, int $contractId, int $amount, ?int $userId = null): array
    {
        if ($amount <= 0) {
            throw new DomainException('مبلغ استخدام الرصيد غير صالح.');
        }

        return DB::transaction(function () use ($customer, $contractId, $amount, $userId) {
            $contract = Contract::query()->findOrFail($contractId);
            if ((int) $contract->customer_id !== (int) $customer->id) {
                throw new DomainException('العقد لا يخص هذا العميل.');
            }

            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ($amount > (int) $locked->credit_balance) {
                throw new DomainException('رصيد العميل الدائن غير كافٍ.');
            }

            $payment = $this->accounting->recordPayment([
                'amount' => $amount,
                'method' => 'credit',
                'direction' => 'in',
                'reference' => 'CR-'.$contractId.'-'.now()->format('YmdHis'),
                'link_type' => 'contract',
                'link_id' => $contractId,
                'notes' => 'استخدام رصيد دائن للعميل',
            ], $userId);

            $locked->credit_balance = (int) $locked->credit_balance - $amount;
            $locked->save();

            $movement = CustomerCreditMovement::query()->create([
                'customer_id' => $locked->id,
                'direction' => 'debit',
                'amount' => $amount,
                'reason' => 'contract_payment',
                'contract_id' => $contractId,
                'payment_id' => $payment->id,
                'recorded_by' => $userId,
                'moved_at' => now(),
                'notes' => 'خصم رصيد دائن لسداد عقد',
            ]);

            return [
                'customer' => $locked->fresh(),
                'movement' => $movement,
                'payment' => $payment,
            ];
        });
    }

    /**
     * @return list<CustomerCreditMovement>
     */
    public function ledger(Customer $customer): array
    {
        return CustomerCreditMovement::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('moved_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }
}
