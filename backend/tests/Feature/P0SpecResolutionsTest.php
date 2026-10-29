<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Models\Employee;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\PettyCashAccount;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class P0SpecResolutionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Vehicle $vehicle;

    private Vehicle $replacement;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->user = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->user);
        app(AccountingService::class)->ensureChartOfAccounts();

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل رصيد',
            'phone' => '01009998877',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-P0',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
            'credit_balance' => 0,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'P0-1', 'plate' => 'P0 111', 'brand' => 'هيونداي', 'model' => 'إلنترا',
            'year' => 2023, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 10000, 'daily_rate' => 1000, 'default_deposit' => 2000, 'is_active' => true,
        ]);
        $this->replacement = Vehicle::query()->create([
            'code' => 'P0-2', 'plate' => 'P0 222', 'brand' => 'كيا', 'model' => 'سيراتو',
            'year' => 2024, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 5000, 'daily_rate' => 1000, 'default_deposit' => 2000, 'is_active' => true,
        ]);
    }

    public function test_same_contract_fault_swap_keeps_id_and_accumulates_km(): void
    {
        $id = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subDays(2)->toIso8601String(),
            'planned_ends_at' => now()->addDays(5)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
            'daily_km_allowance' => 200,
            'excess_km_rate' => 5,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 10000,
            'signature_data' => str_repeat('sig', 20),
        ])->assertOk();

        $swap = $this->postJson("/api/contracts/{$id}/swap", [
            'replacement_vehicle_id' => $this->replacement->id,
            'odometer_in' => 10200,
            'odometer_out' => 5000,
            'fault_description' => 'عطل تبريد',
        ])->assertOk();

        $this->assertSame($id, $swap->json('data.id'));
        $this->assertSame($this->replacement->id, $swap->json('data.vehicle_id'));
        $this->assertDatabaseHas('contract_vehicle_swaps', [
            'contract_id' => $id,
            'from_vehicle_id' => $this->vehicle->id,
            'to_vehicle_id' => $this->replacement->id,
        ]);
        $this->assertSame(1, Contract::query()->where('number', $swap->json('data.number'))->count());

        $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 5300,
            'damage_fee' => 0,
        ])->assertOk();

        $contract = Contract::query()->with('vehicleSwaps')->findOrFail($id);
        $drivenFromSwaps = (int) $contract->vehicleSwaps->sum(fn ($s) => $s->drivenKm());
        $this->assertSame(200, $drivenFromSwaps);
        // Final segment on replacement: 5300 - 5000 = 300 → total driven 500
        $driven = $drivenFromSwaps + max(0, 5300 - 5000);
        $this->assertSame(500, $driven);
        $this->assertSame(max(0, 500 - (int) $contract->allowed_km), (int) $contract->excess_km);
    }

    public function test_early_termination_creates_client_credit_balance(): void
    {
        $id = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subDays(1)->toIso8601String(),
            'planned_ends_at' => now()->addDays(9)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
            'min_days' => 1,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 10000,
            'signature_data' => str_repeat('sig', 20),
        ])->assertOk();

        app(AccountingService::class)->recordPayment([
            'amount' => 10000,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'notes' => 'دفعة مقدمة',
        ], $this->user->id);

        $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 10100,
            'damage_fee' => 0,
        ])->assertOk();

        $contract = Contract::query()->findOrFail($id);
        $this->customer->refresh();
        $this->assertGreaterThan(0, (int) $this->customer->credit_balance);
        $this->assertSame(
            10000 - (int) $contract->final_total,
            (int) $this->customer->credit_balance
        );
        $this->assertDatabaseHas('customer_credit_movements', [
            'customer_id' => $this->customer->id,
            'direction' => 'credit',
            'reason' => 'early_termination',
            'contract_id' => $id,
        ]);

        $apply = $this->postJson("/api/customers/{$this->customer->id}/apply-credit", [
            'contract_id' => $id,
            'amount' => min(500, (int) $this->customer->credit_balance),
        ]);
        $apply->assertOk();
        $this->assertDatabaseHas('payments', [
            'link_type' => 'contract',
            'link_id' => $id,
            'method' => 'credit',
            'direction' => 'in',
        ]);
    }

    public function test_inventory_usage_posts_journal_purchase_does_not(): void
    {
        $item = InventoryItem::query()->create([
            'code' => 'OIL-1', 'name' => 'زيت', 'category' => 'زيوت', 'unit' => 'لتر', 'reorder_level' => 1, 'is_active' => true,
        ]);
        InventoryBatch::query()->create([
            'inventory_item_id' => $item->id,
            'received_on' => now()->toDateString(),
            'quantity' => 10,
            'remaining' => 10,
            'unit_cost' => 100,
            'supplier' => 'مورد',
        ]);

        $before = JournalEntry::query()->where('source', 'inventory_usage')->count();

        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10000,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'lines' => [[
                'description' => 'تغيير زيت',
                'part_source' => 'stock',
                'inventory_item_id' => $item->id,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame($before + 1, JournalEntry::query()->where('source', 'inventory_usage')->count());
        $entry = JournalEntry::query()->where('source', 'inventory_usage')->latest('id')->first();
        $expense = Account::query()->where('code', '5300')->firstOrFail();
        $inventory = Account::query()->where('code', '1400')->firstOrFail();
        $this->assertTrue(JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $expense->id)->where('debit', 200)->exists());
        $this->assertTrue(JournalLine::query()->where('journal_entry_id', $entry->id)->where('account_id', $inventory->id)->where('credit', 200)->exists());
    }

    public function test_expense_from_custody_credits_custody_account_not_cash(): void
    {
        $employee = Employee::query()->create([
            'name' => 'أحمد',
            'base_salary' => 5000,
            'status' => 'active',
        ]);
        PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 10000,
            'balance' => 10000,
            'is_active' => true,
        ]);

        $this->postJson('/api/expenses', [
            'title' => 'شراء منظفات',
            'category' => 'تشغيل',
            'amount' => 500,
            'spent_on' => now()->toDateString(),
            'from_petty_cash' => true,
            'employee_id' => $employee->id,
        ])->assertCreated();

        $custody = Account::query()->where('code', '1500')->firstOrFail();
        $this->assertTrue(
            JournalLine::query()
                ->where('account_id', $custody->id)
                ->where('credit', 500)
                ->exists()
        );
        $this->assertSame(9500, (int) PettyCashAccount::query()->where('employee_id', $employee->id)->value('balance'));
    }
}
