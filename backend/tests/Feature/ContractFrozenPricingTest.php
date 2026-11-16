<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractFrozenPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_vehicle_daily_rate_change_does_not_alter_frozen_contract_rate(): void
    {
        [$customer, $vehicle] = $this->seedParty(800, 2000);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(4)->startOfDay()->toIso8601String(),
            'daily_rate' => 800,
            'deposit' => 2000,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $this->assertSame(800, (int) $this->getJson("/api/contracts/{$id}")->json('data.frozen_daily_rate'));

        $vehicle->update(['daily_rate' => 1500, 'default_deposit' => 9000, 'plate' => 'ف ز 9']);
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();

        $contract = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame(800, (int) $contract['frozen_daily_rate']);
        $this->assertSame(2000, (int) $contract['frozen_agreed_deposit']);
    }

    public function test_written_daily_rate_at_create_sticks_without_price_override_permission(): void
    {
        [$customer, $vehicle] = $this->seedParty(1000, 3000);

        $role = Role::query()->create([
            'name' => 'sales_no_override_'.uniqid(),
            'label' => 'مبيعات بلا تجاوز',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['contracts.view', 'contracts.create', 'contracts.approve'])->pluck('id')
        );
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->startOfDay()->toIso8601String(),
            'daily_rate' => 650,
            'deposit' => 1500,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $this->assertSame(650, (int) $this->getJson("/api/contracts/{$id}")->json('data.frozen_daily_rate'));
        $this->assertSame(1500, (int) $this->getJson("/api/contracts/{$id}")->json('data.frozen_agreed_deposit'));

        $vehicle->update(['daily_rate' => 2000, 'default_deposit' => 9000]);
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();

        $dossier = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame(650, (int) $dossier['frozen_daily_rate']);
        $this->assertSame(1500, (int) $dossier['frozen_agreed_deposit']);
        $days = max(1, (int) ($dossier['approved_days'] ?? 1));
        $this->assertSame(650 * $days, (int) $dossier['ledger']['contract_total']);
    }

    public function test_vehicle_dossier_exposes_contract_frozen_daily_rate_not_vehicle_default(): void
    {
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));

        [$customer, $vehicle] = $this->seedParty(1200, 2500);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->startOfDay()->toIso8601String(),
            'daily_rate' => 777,
            'deposit' => 1111,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $vehicle->update(['daily_rate' => 9999]);

        $row = collect($this->getJson("/api/vehicles/{$vehicle->id}/dossier")->assertOk()->json('data.contracts'))
            ->firstWhere('id', $id);

        $this->assertNotNull($row);
        $this->assertSame(777, (int) $row['frozen_daily_rate']);
        $this->assertSame(1111, (int) $row['frozen_agreed_deposit']);
        $this->assertNotSame(9999, (int) $row['frozen_daily_rate']);
    }

    /**
     * @return array{0: Customer, 1: Vehicle}
     */
    private function seedParty(int $vehicleRate, int $deposit): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل تجميد',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'FZ-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'FZ-'.uniqid(),
            'plate' => 'ف ز '.random_int(1, 999),
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => $vehicleRate,
            'default_deposit' => $deposit,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }
}
