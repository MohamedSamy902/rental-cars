<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeOperationalDataCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'مركز اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_purge_keeps_users_roles_settings_and_chart_but_clears_ops_and_journals(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create([
            'email' => 'admin@purge.test',
            'role_id' => $role->id,
            'is_active' => true,
        ]);
        $ops = User::factory()->create([
            'email' => 'ops@purge.test',
            'role_id' => Role::query()->where('name', 'ops_manager')->value('id') ?? $role->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل يُمسح',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-PURGE',
            'license_expires_on' => '2030-01-01',
            'status' => 'active',
            'credit_balance' => 50,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-PURGE',
            'plate' => 'ق ج 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 100,
            'default_deposit' => 0,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-PURGE-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now(),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 100,
            'approved_days' => 3,
            'period_rent' => 300,
            'deposit' => 0,
            'odometer_out' => 1000,
            'excess_km_amount' => 0,
            'damage_fee' => 0,
            'other_fees' => 0,
            'discount' => 0,
        ]);
        Payment::query()->create([
            'amount' => 100,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'P1',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => $admin->id,
        ]);

        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $ar = Account::query()->where('code', '1200')->firstOrFail();
        $entry = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'قيد تجريبي',
            'source' => 'purge_test:1',
            'posted_by' => $admin->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 100,
            'credit' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => 100,
        ]);

        $this->artisan('rpm:purge-operational-data', ['--force' => true, '--keep-catalogs' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => 'admin@purge.test']);
        $this->assertDatabaseHas('users', ['id' => $ops->id, 'email' => 'ops@purge.test']);
        $this->assertDatabaseHas('tenant_settings', ['center_name' => 'مركز اختبار']);
        $this->assertSame(17, Account::query()->count());
        $this->assertSame(0, Contract::withTrashed()->count());
        $this->assertSame(0, Customer::withTrashed()->count());
        $this->assertSame(0, Vehicle::withTrashed()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
        $this->assertGreaterThan(0, Role::query()->count());
    }
}
