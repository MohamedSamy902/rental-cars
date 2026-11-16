<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);

        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $salesRole = Role::query()->where('name', 'sales')->first()
            ?? Role::query()->where('name', '!=', 'super_admin')->firstOrFail();
        $this->staff = User::factory()->create([
            'role_id' => $salesRole->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل حذف',
            'phone' => '01001234567',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-DEL',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-DEL',
            'plate' => 'س ص 111',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'odometer_source' => 'manual',
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $this->contract = Contract::query()->create([
            'number' => 'CNT-DEL-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'completed',
            'starts_at' => now()->subDays(10),
            'planned_ends_at' => now()->subDay(),
            'actual_ends_at' => now()->subDay(),
            'approved_days' => 9,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'odometer_out' => 10000,
            'odometer_in' => 10500,
            'period_rent' => 4500,
            'final_total' => 4500,
        ]);

        $payment = Payment::query()->create([
            'amount' => 4500,
            'paid_at' => now()->subDays(2),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'PAY-DEL-1',
            'link_type' => 'contract',
            'link_id' => $this->contract->id,
            'user_id' => $this->admin->id,
        ]);

        $entry = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'إيجار',
            'source' => 'rent_recog:'.$this->contract->id.':'.now()->format('Y-m'),
            'posted_by' => $this->admin->id,
        ]);
        $cash = \App\Models\Account::query()->where('code', '1000')->first()
            ?? \App\Models\Account::query()->firstOrFail();
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 4500,
            'credit' => 0,
        ]);
        JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'قبض',
            'source' => 'payment:'.$payment->id,
            'posted_by' => $this->admin->id,
        ]);

        Deposit::query()->create([
            'contract_id' => $this->contract->id,
            'original_amount' => 1000,
            'status' => 'held',
            'received_at' => now()->subDays(9),
            'receipt_number' => 'D-DEL-1',
            'collection_method' => 'cash',
            'deductions_total' => 0,
        ]);

        Violation::query()->create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
            'contract_id' => $this->contract->id,
            'occurred_at' => now()->subDays(5),
            'amount' => 200,
            'status' => 'assigned',
            'created_by' => $this->admin->id,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'workshop' => 'ورشة',
            'opened_on' => now()->subDays(3)->toDateString(),
            'odometer' => 10400,
            'status' => 'open',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);
    }

    public function test_staff_cannot_soft_delete_contract(): void
    {
        Sanctum::actingAs($this->staff);

        $this->deleteJson('/api/contracts/'.$this->contract->id)
            ->assertForbidden();

        $this->assertDatabaseHas('contracts', [
            'id' => $this->contract->id,
            'deleted_at' => null,
        ]);
    }

    public function test_super_admin_soft_deletes_contract_and_finance(): void
    {
        Sanctum::actingAs($this->admin);

        $this->deleteJson('/api/contracts/'.$this->contract->id)
            ->assertOk()
            ->assertJsonPath('data.counts.contracts', 1);

        $this->assertSoftDeleted('contracts', ['id' => $this->contract->id]);
        $this->assertSoftDeleted('payments', [
            'link_type' => 'contract',
            'link_id' => $this->contract->id,
        ]);
        $this->assertSoftDeleted('deposits', ['contract_id' => $this->contract->id]);
        $this->assertSoftDeleted('violations', ['contract_id' => $this->contract->id]);

        $this->assertNull(Contract::query()->find($this->contract->id));
        $this->assertNotNull(Contract::withTrashed()->find($this->contract->id));
        $this->assertStringContainsString('__del_', (string) Contract::withTrashed()->find($this->contract->id)?->number);

        $this->assertSame(0, Payment::query()->where('link_type', 'contract')->where('link_id', $this->contract->id)->count());
        $this->assertSame(0, JournalEntry::query()->where('source', 'like', 'rent_recog:'.$this->contract->id.':%')->count());
        $this->assertGreaterThan(
            0,
            JournalEntry::withTrashed()
                ->where('source', 'like', 'rent_recog:'.$this->contract->id.':%__del_%')
                ->count()
        );
    }

    public function test_soft_purge_all_requires_confirm_and_clears_contracts_and_work_orders(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/contracts/soft-purge-all', ['confirm' => 'لا'])
            ->assertStatus(422);

        $this->postJson('/api/contracts/soft-purge-all', ['confirm' => 'حذف الكل'])
            ->assertOk()
            ->assertJsonPath('data.contracts', 1);

        $this->assertSame(0, Contract::query()->count());
        $this->assertSame(0, WorkOrder::query()->count());
        $this->assertGreaterThan(0, Contract::withTrashed()->count());
        $this->assertGreaterThan(0, WorkOrder::withTrashed()->count());
    }
}
