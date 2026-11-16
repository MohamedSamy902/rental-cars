<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractWeeklyMonthlyRentTest extends TestCase
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
                'showroom_half_monthly_factor' => 15,
                'showroom_monthly_factor' => 22,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_create_1_to_10_days_uses_daily_tier(): void
    {
        [$customer, $vehicle] = $this->seedParty(1000);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(11)->startOfDay()->toIso8601String(), // 10 أيام
            'daily_rate' => 1000,
            'half_monthly_rate' => 15000,
            'monthly_rate' => 22000,
            'deposit' => 2000,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $show = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame('daily', $show['rent_basis']);
        $this->assertSame(15000, (int) $show['frozen_half_monthly_rate']);
        $this->assertSame(10000, (int) $show['ledger']['contract_total']);
    }

    public function test_create_11_to_20_days_uses_half_monthly_pack(): void
    {
        [$customer, $vehicle] = $this->seedParty(1000);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(19)->startOfDay()->toIso8601String(), // 18 يوماً
            'daily_rate' => 1000,
            'half_monthly_rate' => 15000,
            'monthly_rate' => 22000,
            'deposit' => 2000,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $show = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame('half_monthly', $show['rent_basis']);
        $this->assertSame(15000, (int) $show['frozen_half_monthly_rate']);
        // 18 يوماً = 15000 + round(3*15000/15) = 18000
        $this->assertSame(18000, (int) $show['ledger']['contract_total']);
    }

    public function test_create_21_plus_days_uses_monthly_pack_with_extra_day_cost(): void
    {
        [$customer, $vehicle] = $this->seedParty(1000);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(33)->startOfDay()->toIso8601String(), // 32 يوماً
            'daily_rate' => 1000,
            'half_monthly_rate' => 15000,
            'monthly_rate' => 22000,
            'deposit' => 2000,
            'odometer' => 5000,
        ])->assertCreated()->json('data.id');

        $show = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame('monthly', $show['rent_basis']);
        $this->assertSame(22000, (int) $show['frozen_monthly_rate']);
        // 32 يوماً = 22000 + round(2*22000/30) = 23467
        $this->assertSame(23467, (int) $show['ledger']['contract_total']);
    }

    /**
     * @return array{0: Customer, 1: Vehicle}
     */
    private function seedParty(int $daily): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل باقات',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'PKG-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'PKG-'.uniqid(),
            'plate' => 'ب ق '.random_int(1, 999),
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => $daily,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }
}
