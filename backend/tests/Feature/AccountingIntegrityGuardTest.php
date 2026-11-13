<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PettyCashAccount;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\HistoricAccountingRemediationService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingIntegrityGuardTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $this->accounting = app(AccountingService::class);
        $this->accounting->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['petty_cash' => true, 'inventory' => true],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_early_close_reverses_over_accrued_rent_including_unused_months(): void
    {
        [$customer, $vehicle] = $this->party('ER');
        $contract = Contract::query()->create([
            'number' => 'C-ER-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => '2026-01-01T00:00:00+02:00',
            'planned_ends_at' => '2026-03-01T00:00:00+02:00',
            'frozen_daily_rate' => 100,
            'approved_days' => 59,
            'period_rent' => 5900,
            'deposit' => 0,
            'odometer_out' => 1000,
            'excess_km_amount' => 0,
            'damage_fee' => 0,
            'other_fees' => 0,
            'discount' => 0,
        ]);

        // Accrue full planned months while still active (overview/sync path).
        $this->accounting->recognizeRentForMonth(2026, 1);
        $this->accounting->recognizeRentForMonth(2026, 2);
        $this->accounting->recognizeRentForMonth(2026, 3);

        $before = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '4000'))
            ->sum('credit');
        $this->assertGreaterThan(0, $before);

        // Early return: only January rent remains.
        $contract->update([
            'status' => 'completed',
            'actual_ends_at' => '2026-01-10T00:00:00+02:00',
            'period_rent' => 900,
            'final_total' => 900,
        ]);

        $this->accounting->postContractClose($contract->fresh());

        $rent = Account::query()->where('code', '4000')->firstOrFail();
        $netRent = (int) JournalLine::query()->where('account_id', $rent->id)->sum('credit')
            - (int) JournalLine::query()->where('account_id', $rent->id)->sum('debit');
        $this->assertSame(900, $netRent);
        $this->assertSame(0, $this->accounting->rentRecognizedNet($contract->id, 2026, 2));
        $this->assertSame(0, $this->accounting->rentRecognizedNet($contract->id, 2026, 3));
        $this->assertTrue(JournalEntry::query()
            ->where('source', sprintf('rent_recog_adj:%d:2026-02', $contract->id))
            ->exists());
    }

    public function test_locked_month_is_not_topped_up_by_recognition(): void
    {
        [$customer, $vehicle] = $this->party('LK');
        $contract = Contract::query()->create([
            'number' => 'C-LK-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => '2026-05-01T00:00:00+02:00',
            'planned_ends_at' => '2026-05-10T00:00:00+02:00',
            'frozen_daily_rate' => 100,
            'approved_days' => 9,
            'period_rent' => 900,
            'deposit' => 0,
            'odometer_out' => 1000,
            'excess_km_amount' => 0,
            'damage_fee' => 0,
            'other_fees' => 0,
            'discount' => 0,
        ]);

        $this->accounting->lockMonth(2026, 5);

        $this->expectException(\DomainException::class);
        $this->accounting->recognizeRentForMonth(2026, 5);
    }

    public function test_petty_cash_store_posts_custody_journal(): void
    {
        $employee = Employee::query()->create([
            'name' => 'أمين الصندوق',
            'job_title' => 'أمين',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);

        $this->postJson('/api/petty-cash', [
            'employee_id' => $employee->id,
            'limit_amount' => 0,
            'balance' => 1000,
        ])->assertCreated();

        $account = PettyCashAccount::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(1000, (int) $account->balance);
        $this->assertTrue(JournalEntry::query()->where('source', 'like', 'petty_topup:%')->exists());

        $custody = Account::query()->where('code', '1500')->firstOrFail();
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $this->assertSame(1000, (int) JournalLine::query()->where('account_id', $custody->id)->sum('debit'));
        $this->assertSame(1000, (int) JournalLine::query()->where('account_id', $cash->id)->sum('credit'));
    }

    public function test_petty_cash_direct_spend_is_rejected(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف الصرف',
            'job_title' => 'أمين',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 0,
            'balance' => 500,
            'is_active' => true,
        ]);

        $this->postJson("/api/petty-cash/{$account->id}/move", [
            'type' => 'spend',
            'amount' => 100,
        ])->assertStatus(422);
    }

    public function test_remediation_does_not_double_post_deposit_payments(): void
    {
        [$customer, $vehicle] = $this->party('DP');
        $contract = Contract::query()->create([
            'number' => 'C-DP-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => '2026-06-01T00:00:00+02:00',
            'planned_ends_at' => '2026-06-05T00:00:00+02:00',
            'frozen_daily_rate' => 100,
            'approved_days' => 4,
            'period_rent' => 400,
            'deposit' => 500,
            'odometer_out' => 1000,
            'excess_km_amount' => 0,
            'damage_fee' => 0,
            'other_fees' => 0,
            'discount' => 0,
        ]);

        $depositPayment = Payment::query()->create([
            'amount' => 500,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'DEP-HOLD',
            'link_type' => 'deposit',
            'link_id' => 1,
            'user_id' => null,
        ]);

        // Live path: deposit hold journal only (no payment:*).
        $hold = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'قبض تأمين',
            'source' => 'deposit_hold:1',
            'posted_by' => null,
        ]);
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $liab = Account::query()->where('code', '1300')->firstOrFail();
        JournalLine::query()->create([
            'journal_entry_id' => $hold->id,
            'account_id' => $cash->id,
            'debit' => 500,
            'credit' => 0,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $hold->id,
            'account_id' => $liab->id,
            'debit' => 0,
            'credit' => 500,
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
        ]);

        $result = app(HistoricAccountingRemediationService::class)->run();
        $this->assertSame(0, $result['payment_journals']);
        $this->assertFalse(JournalEntry::query()->where('source', 'payment:'.$depositPayment->id)->exists());
        $this->assertSame(500, (int) JournalLine::query()->where('account_id', $cash->id)->sum('debit'));

        // Contract cash payment still backfilled.
        Payment::query()->create([
            'amount' => 100,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'RENT',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => null,
        ]);
        $again = app(HistoricAccountingRemediationService::class)->run();
        $this->assertSame(1, $again['payment_journals']);
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
}
