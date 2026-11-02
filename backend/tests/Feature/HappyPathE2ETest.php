<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end happy path covering the 7 client verification requirements:
 * RBAC, owner+2 cars+custody+stock, WO custody vs FIFO, contract lifecycle,
 * print payloads, and seed-ready entities for browser inspection.
 */
class HappyPathE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'مركز اختبار E2E',
            'print_footer' => 'تذييل طباعة E2E',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => true,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        app(AccountingService::class)->ensureChartOfAccounts();

        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $maintRole = Role::query()->where('name', 'maintenance')->firstOrFail();
        $maintRole->permissions()->sync(
            \App\Models\Permission::query()
                ->where(function ($q) {
                    $q->where('key', 'like', 'maintenance.%')
                        ->orWhere('key', 'like', 'inventory.%')
                        ->orWhere('key', 'like', 'petty_cash.%')
                        ->orWhere('key', 'vehicles.view')
                        ->orWhere('key', 'like', 'media.%');
                })
                ->pluck('id')
        );

        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
            'name' => 'مدير E2E',
            'email' => 'admin-e2e@rpm.test',
        ]);
        $this->technician = User::factory()->create([
            'role_id' => $maintRole->id,
            'is_active' => true,
            'name' => 'فني E2E',
            'email' => 'tech-e2e@rpm.test',
        ]);
    }

    public function test_rbac_admin_vs_technician_on_sensitive_actions(): void
    {
        Sanctum::actingAs($this->technician);

        $this->postJson('/api/contracts/1/approve')
            ->assertStatus(403)
            ->assertJsonPath('message', 'ليست لديك صلاحية لتنفيذ هذا الإجراء.');

        $this->postJson('/api/accounting/lock-month', [
            'year' => 2026,
            'month' => 11,
        ])->assertStatus(403);

        Sanctum::actingAs($this->admin);
        // Admin reaches domain layer (404/422) rather than permission 403.
        $this->postJson('/api/contracts/999999/approve')
            ->assertStatus(404);
    }

    public function test_full_scenario_owner_vehicles_custody_stock_lifecycle_and_print(): void
    {
        Sanctum::actingAs($this->admin);

        // --- 2) Owner + 2 vehicles + employee custody + inventory ---
        $ownerRes = $this->postJson('/api/owners', [
            'full_name' => 'خالد عبد الله E2E',
            'phone' => '01055550101',
            'national_id' => '28001019900101',
            'is_active' => true,
        ])->assertCreated();
        $ownerId = (int) $ownerRes->json('data.id');
        $this->assertDatabaseHas('vehicle_owners', ['id' => $ownerId, 'phone' => '01055550101']);

        $v1 = $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '1010',
            'brand' => 'شيري',
            'model' => 'تيجو 7',
            'year' => 2024,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1200,
            'default_deposit' => 4000,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 9000,
            'owner_since' => now()->toDateString(),
        ])->assertCreated();
        $v2 = $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '2020',
            'brand' => 'MG',
            'model' => 'ZS',
            'year' => 2023,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 1100,
            'default_deposit' => 4000,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 8500,
            'owner_since' => now()->toDateString(),
        ])->assertCreated();

        $vehicleA = Vehicle::query()->findOrFail($v1->json('data.id'));
        $vehicleB = Vehicle::query()->findOrFail($v2->json('data.id'));
        $this->assertStringStartsWith('VEH-', (string) $vehicleA->code);
        $this->assertStringStartsWith('VEH-', (string) $vehicleB->code);
        $this->assertSame($ownerId, (int) $vehicleA->owner_id);

        $employee = Employee::query()->create([
            'name' => 'محمود فني E2E',
            'job_title' => 'فني صيانة',
            'hired_on' => now()->toDateString(),
            'base_salary' => 6000,
            'status' => 'active',
        ]);
        $petty = $this->postJson('/api/petty-cash', [
            'employee_id' => $employee->id,
            'limit_amount' => 10000,
            'balance' => 10000,
        ])->assertCreated();
        $pettyId = (int) ($petty->json('data.id') ?? PettyCashAccount::query()->where('employee_id', $employee->id)->value('id'));
        $this->assertGreaterThan(0, $pettyId);

        $item = $this->postJson('/api/inventory/items', [
            'code' => 'OIL-E2E',
            'name' => 'زيت محرك E2E',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 2,
        ])->assertCreated();
        $itemId = (int) $item->json('data.id');
        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => now()->subDays(10)->toDateString(),
            'quantity' => 10,
            'unit_cost' => 100,
            'supplier' => 'مورد E2E',
        ])->assertCreated();
        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => now()->subDays(2)->toDateString(),
            'quantity' => 5,
            'unit_cost' => 150,
            'supplier' => 'مورد E2E',
        ])->assertCreated();

        // --- 3a) Maintenance from custody ---
        $spare = Vehicle::query()->create([
            'code' => 'VEH-SPARE',
            'plate' => 'س ص ع 3333',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 50000,
            'daily_rate' => 700,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        $woCustody = $this->postJson('/api/work-orders', [
            'vehicle_id' => $spare->id,
            'odometer' => 50000,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'workshop' => 'ورشة العهدة',
            'lines' => [[
                'description' => 'قطعة من العهدة',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 800,
                'from_petty_cash' => true,
                'petty_cash_employee_id' => $employee->id,
                'petty_cash_amount' => 800,
            ]],
        ])->assertCreated();

        $this->assertSame(9200, (int) PettyCashAccount::query()->findOrFail($pettyId)->balance);
        $this->assertTrue(
            PettyCashMovement::query()
                ->where('petty_cash_account_id', $pettyId)
                ->where('type', 'spend')
                ->where('amount', 800)
                ->where('work_order_id', $woCustody->json('data.id'))
                ->exists()
        );
        $ledger = $this->getJson("/api/petty-cash/{$pettyId}")->assertOk();
        $this->assertNotEmpty($ledger->json('data.ledger'));
        $this->assertTrue(
            collect($ledger->json('data.ledger'))->contains(fn ($row) => (int) ($row['amount'] ?? 0) === 800)
        );

        // --- 3b) Maintenance from inventory FIFO (no manual price) ---
        $woStock = $this->postJson('/api/work-orders', [
            'vehicle_id' => $spare->id,
            'odometer' => 50010,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'workshop' => 'ورشة المخزون',
            'lines' => [[
                'description' => 'زيت من المخزون',
                'part_source' => 'stock',
                'inventory_item_id' => $itemId,
                'quantity' => 3,
            ]],
        ])->assertCreated();

        $stockLine = collect($woStock->json('data.lines') ?? [])->first();
        $this->assertNotNull($stockLine);
        // FIFO: 3 × 100 from oldest batch
        $this->assertSame(100, (int) $stockLine['unit_price']);
        $remaining = (int) InventoryBatch::query()
            ->where('inventory_item_id', $itemId)
            ->orderBy('received_on')
            ->value('remaining');
        $this->assertSame(7, $remaining);

        // --- 4) Contract lifecycle: book → approve → handover → extend → swap → cancel ---
        $customer = Customer::query()->create([
            'full_name' => 'عميل E2E',
            'phone' => '01011110001',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-E2E',
            'license_expires_on' => now()->addYears(3)->toDateString(),
            'status' => 'active',
        ]);

        $create = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicleA->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->toIso8601String(),
            'daily_rate' => 1200,
            'deposit' => 4000,
        ])->assertCreated();
        $contractId = (int) $create->json('data.id');

        $this->postJson("/api/contracts/{$contractId}/approve")->assertOk();
        $this->postJson("/api/contracts/{$contractId}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'RCP-E2E-1',
        ])->assertCreated();

        $signature = 'sig-'.str_repeat('client-ok-', 6);
        $this->postJson("/api/contracts/{$contractId}/handover", [
            'odometer_out' => 1000,
            'signature_data' => $signature,
            'fuel_level' => 80,
            'accessories' => [
                ['name' => 'مثلث تحذير', 'expected_qty' => 1, 'actual_qty' => 1, 'ok' => true],
            ],
        ])->assertOk()->assertJsonPath('data.status', 'active');

        // Extend → new contract from planned end
        $extendEnds = now()->addDays(3)->addDays(4)->toIso8601String();
        $extended = $this->postJson("/api/contracts/{$contractId}/extend", [
            'planned_ends_at' => $extendEnds,
        ])->assertCreated();
        $extId = (int) $extended->json('data.id');
        $this->assertNotSame($contractId, $extId);
        $this->assertSame($vehicleA->id, (int) $extended->json('data.vehicle_id'));
        $this->assertSame(1200, (int) $extended->json('data.frozen_daily_rate'));

        // Swap mid-active contract onto vehicle B
        $swapped = $this->postJson("/api/contracts/{$contractId}/swap", [
            'replacement_vehicle_id' => $vehicleB->id,
            'odometer_in' => 1050,
            'odometer_out' => 2000,
            'signature_data' => $signature,
            'fuel_level' => 70,
            'fault_description' => 'عطل مكيف أثناء الإيجار',
        ])->assertOk();
        $this->assertSame($vehicleB->id, (int) $swapped->json('data.vehicle_id'));
        $vehicleA->refresh();
        $this->assertSame('maintenance', $vehicleA->manual_status);

        // Cancel a separate pending booking and release vehicle
        $pendingVehicle = Vehicle::query()->create([
            'code' => 'VEH-CXL',
            'plate' => 'ق ر ش 4444',
            'brand' => 'هيونداي',
            'model' => 'إكسينت',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 30000,
            'daily_rate' => 800,
            'default_deposit' => 2500,
            'is_active' => true,
        ]);
        $pending = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $pendingVehicle->id,
            'starts_at' => now()->addDays(10)->toIso8601String(),
            'planned_ends_at' => now()->addDays(13)->toIso8601String(),
        ])->assertCreated();
        $pendingId = (int) $pending->json('data.id');
        $this->postJson("/api/contracts/{$pendingId}/cancel", ['reason' => 'طلب العميل — إلغاء قبل التسليم'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Overlap after cancel should be allowed again
        $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $pendingVehicle->id,
            'starts_at' => now()->addDays(10)->toIso8601String(),
            'planned_ends_at' => now()->addDays(12)->toIso8601String(),
        ])->assertCreated();

        // --- 5) Print payloads for all 3 document kinds ---
        $print = $this->getJson("/api/contracts/{$contractId}/print")->assertOk();
        $this->assertNotEmpty($print->json('data.contract.number'));
        $this->assertNotEmpty($print->json('data.tenant.center_name'));
        $this->assertArrayHasKey('customer', $print->json('data.contract'));
        $this->assertArrayHasKey('deposit', $print->json('data.contract'));

        foreach (['contract', 'invoice', 'receipt'] as $type) {
            $this->postJson('/api/print-snapshots', [
                'type' => $type,
                'record_type' => 'contract',
                'record_id' => $contractId,
                'payload' => $print->json('data'),
            ])->assertSuccessful();
        }

        // Technician still blocked from approve/cancel even after scenario exists
        Sanctum::actingAs($this->technician);
        $this->postJson("/api/contracts/{$pendingId}/approve")->assertStatus(403);
        $this->postJson("/api/contracts/{$extId}/cancel", ['reason' => 'محاولة غير مصرح بها'])->assertStatus(403);
    }

    public function test_demo_seeder_leaves_inspectable_happy_path_state(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $this->assertTrue(User::query()->where('email', 'admin@rpm.local')->exists());
        $this->assertTrue(User::query()->where('email', 'maint@rpm.local')->exists());
        $this->assertTrue(VehicleOwner::query()->where('phone', '01055556666')->exists());
        $this->assertSame(2, Vehicle::query()->whereIn('code', ['VEH-9001', 'VEH-9002'])->count());
        $this->assertTrue(Employee::query()->where('name', 'محمود فني')->exists());
        $empId = (int) Employee::query()->where('name', 'محمود فني')->value('id');
        $this->assertTrue(PettyCashAccount::query()->where('employee_id', $empId)->where('is_active', true)->exists());
        $this->assertTrue(InventoryItem::query()->where('code', 'OIL-5W30')->exists());
        $this->assertTrue(Contract::query()->where('number', 'CNT-E2E-01')->exists());

        $sales = User::query()->where('email', 'sales@rpm.local')->firstOrFail();
        $this->assertFalse($sales->hasPermission('contracts.approve'));
        $this->assertFalse($sales->hasPermission('accounting.lock_month'));
        $admin = User::query()->where('email', 'admin@rpm.local')->firstOrFail();
        $this->assertTrue($admin->hasPermission('contracts.approve'));
    }
}
