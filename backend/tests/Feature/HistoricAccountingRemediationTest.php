<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\HistoricAccountingRemediationService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricAccountingRemediationTest extends TestCase
{
    use RefreshDatabase;

    private HistoricAccountingRemediationService $remediation;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['inventory' => true],
            'operational' => [],
        ]);
        $this->remediation = app(HistoricAccountingRemediationService::class);
    }

    public function test_backfills_missing_payment_journals_and_exposes_overpay_credit(): void
    {
        [$customer, $vehicle] = $this->party('OP');
        $contract = $this->seedContract($customer, $vehicle, 400, '2026-03-01', '2026-03-05');

        Payment::query()->create([
            'amount' => 100,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'P51',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => null,
        ]);
        Payment::query()->create([
            'amount' => 301,
            'paid_at' => now(),
            'method' => 'نقدي',
            'direction' => 'in',
            'reference' => 'P52',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => null,
        ]);

        $this->assertSame(0, JournalEntry::query()->where('source', 'like', 'payment:%')->count());

        $first = $this->remediation->run();
        $this->assertSame(2, $first['payment_journals']);
        $this->assertSame(1, $first['overpayment_credits']);
        $this->assertTrue(JournalEntry::query()->where('source', 'like', 'payment:%')->count() >= 2);
        $this->assertSame(1, (int) $customer->fresh()->credit_balance);
        $this->assertTrue(CustomerCreditMovement::query()
            ->where('contract_id', $contract->id)
            ->where('notes', HistoricAccountingRemediationService::OVERPAY_NOTE)
            ->exists());

        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $cashDebit = (int) JournalLine::query()->where('account_id', $cash->id)->sum('debit');
        $this->assertSame(401, $cashDebit);

        $second = $this->remediation->run();
        $this->assertSame(0, $second['payment_journals']);
        $this->assertSame(0, $second['overpayment_credits']);
        $this->assertSame(1, (int) $customer->fresh()->credit_balance);
    }

    public function test_realigns_rent_when_close_dumped_all_into_one_month(): void
    {
        [$customer, $vehicle] = $this->party('RN');
        $contract = $this->seedContract($customer, $vehicle, 400, '2026-01-30', '2026-02-03');
        $contract->update([
            'status' => 'completed',
            'actual_ends_at' => '2026-02-03T00:00:00+02:00',
            'period_rent' => 400,
            'final_total' => 400,
        ]);

        $ar = Account::query()->where('code', '1200')->firstOrFail();
        $rent = Account::query()->where('code', '4000')->firstOrFail();

        // Historic bug: full period rent booked on contract_close in February only.
        $close = JournalEntry::query()->create([
            'entry_date' => '2026-02-03',
            'narration' => 'إقفال قديم',
            'source' => 'contract_close:'.$contract->id,
            'posted_by' => null,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $close->id,
            'account_id' => $ar->id,
            'debit' => 400,
            'credit' => 0,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $close->id,
            'account_id' => $rent->id,
            'debit' => 0,
            'credit' => 400,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
        ]);

        $result = $this->remediation->run();
        $this->assertSame(1, $result['contract_close_rent_reversed']);
        $this->assertGreaterThanOrEqual(2, $result['rent_months_aligned']);

        $jan = JournalEntry::query()->where('source', sprintf('rent_recog:%d:2026-01', $contract->id))->first();
        $feb = JournalEntry::query()->where('source', sprintf('rent_recog:%d:2026-02', $contract->id))->first();
        $this->assertNotNull($jan);
        $this->assertNotNull($feb);

        $janCredit = (int) JournalLine::query()
            ->where('journal_entry_id', $jan->id)->where('account_id', $rent->id)->sum('credit');
        $febCredit = (int) JournalLine::query()
            ->where('journal_entry_id', $feb->id)->where('account_id', $rent->id)->sum('credit');
        $this->assertSame(200, $janCredit);
        $this->assertSame(200, $febCredit);

        // Net rent after reversing close dump stays 400 overall.
        $netRent = (int) JournalLine::query()->where('account_id', $rent->id)->sum('credit')
            - (int) JournalLine::query()->where('account_id', $rent->id)->sum('debit');
        $this->assertSame(400, $netRent);

        $again = $this->remediation->run();
        $this->assertSame(0, $again['contract_close_rent_reversed']);
        $this->assertSame(0, $again['rent_months_aligned']);
    }

    public function test_tops_up_rounded_fifo_work_order_cost(): void
    {
        [, $vehicle] = $this->party('FQ');
        $item = InventoryItem::query()->create([
            'code' => 'IT-FQ-'.random_int(100, 999),
            'name' => 'FIFO-QA',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 1,
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'received_on' => '2026-11-01',
            'quantity' => 2,
            'remaining' => 0,
            'unit_cost' => 10,
            'supplier' => 'QA',
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'received_on' => '2026-11-02',
            'quantity' => 3,
            'remaining' => 2,
            'unit_cost' => 20,
            'supplier' => 'QA',
        ]);

        $wo = WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'odometer' => 1000,
            'workshop' => 'QA',
            'opened_on' => '2026-11-08',
            'opened_at' => now(),
            'status' => 'open',
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'parts_cost' => 39,
        ]);
        $line = WorkOrderLine::query()->create([
            'work_order_id' => $wo->id,
            'description' => '3 units',
            'part_source' => 'stock',
            'inventory_item_id' => $item->id,
            'quantity' => 3,
            'unit_price' => 13,
            'from_petty_cash' => false,
            'petty_cash_amount' => 0,
        ]);

        $expense = Account::query()->where('code', '5300')->firstOrFail();
        $inv = Account::query()->where('code', '1400')->firstOrFail();
        $entry = JournalEntry::query()->create([
            'entry_date' => '2026-11-08',
            'narration' => 'صرف قديم',
            'source' => 'inventory_usage:wol:'.$line->id,
            'posted_by' => null,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expense->id,
            'debit' => 39,
            'credit' => 0,
            'vehicle_id' => $vehicle->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $inv->id,
            'debit' => 0,
            'credit' => 39,
            'vehicle_id' => $vehicle->id,
        ]);

        $result = $this->remediation->run();
        $this->assertSame(1, $result['fifo_work_orders']);
        $this->assertSame(40, (int) $wo->fresh()->parts_cost);
        $this->assertTrue(JournalEntry::query()
            ->where('source', 'inventory_usage:wol:'.$line->id.HistoricAccountingRemediationService::FIFO_TOPUP_SUFFIX)
            ->exists());
        $invCredit = (int) JournalLine::query()->where('account_id', $inv->id)->sum('credit');
        $this->assertSame(40, $invCredit);

        $this->assertSame(0, $this->remediation->run()['fifo_work_orders']);
    }

    /** @return array{0: Customer, 1: Vehicle} */
    private function party(string $tag): array
    {
        $customer = Customer::query()->create([
            'full_name' => "عميل {$tag}",
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'L-'.$tag,
            'license_expires_on' => '2030-01-01',
            'status' => 'active',
            'credit_balance' => 0,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-'.$tag.'-'.random_int(100, 999),
            'plate' => 'QA-'.$tag.'-'.random_int(1000, 9999),
            'brand' => 'تويوتا',
            'model' => 'QA',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 100,
            'default_deposit' => 0,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }

    private function seedContract(Customer $customer, Vehicle $vehicle, int $periodRent, string $startDay, string $endDay): Contract
    {
        return Contract::query()->create([
            'number' => 'C-'.random_int(10000, 99999),
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => $startDay.'T00:00:00+02:00',
            'planned_ends_at' => $endDay.'T00:00:00+02:00',
            'frozen_daily_rate' => 100,
            'approved_days' => 4,
            'period_rent' => $periodRent,
            'deposit' => 0,
            'odometer_out' => 1000,
            'excess_km_amount' => 0,
            'damage_fee' => 0,
            'other_fees' => 0,
            'discount' => 0,
        ]);
    }
}
