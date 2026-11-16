<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehiclePackageRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['showroom' => true],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_store_vehicle_persists_package_rates_and_defaults_when_omitted(): void
    {
        $withRates = $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '1111',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1000,
            'half_monthly_rate' => 14000,
            'monthly_rate' => 25000,
            'default_deposit' => 2000,
            'chassis_number' => 'CHS-PKG-1',
        ])->assertCreated()->json('data');

        $this->assertSame(1000, (int) $withRates['daily_rate']);
        $this->assertSame(14000, (int) $withRates['half_monthly_rate']);
        $this->assertSame(25000, (int) $withRates['monthly_rate']);

        $defaults = $this->postJson('/api/vehicles', [
            'plate_letters' => 'د ر س',
            'plate_numbers' => '2222',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 500,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'chassis_number' => 'CHS-PKG-2',
        ])->assertCreated()->json('data');

        $this->assertSame(15000, (int) $defaults['half_monthly_rate']);
        $this->assertSame(22000, (int) $defaults['monthly_rate']);
    }

    public function test_contract_create_freezes_vehicle_package_rates_when_not_overridden(): void
    {
        $vehicleId = $this->postJson('/api/vehicles', [
            'plate_letters' => 'س ص ع',
            'plate_numbers' => '3333',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 1000,
            'half_monthly_rate' => 13500,
            'monthly_rate' => 24000,
            'default_deposit' => 2000,
            'chassis_number' => 'CHS-PKG-3',
        ])->assertCreated()->json('data.id');

        $customer = Customer::query()->create([
            'full_name' => 'عميل أسعار',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'PKG-R-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicleId,
            'starts_at' => now()->addDay()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(19)->startOfDay()->toIso8601String(), // 18 يوماً → نص شهري
            'deposit' => 2000,
            'odometer' => 2000,
        ])->assertCreated()->json('data.id');

        $show = $this->getJson("/api/contracts/{$id}")->assertOk()->json('data');
        $this->assertSame('half_monthly', $show['rent_basis']);
        $this->assertSame(1000, (int) $show['frozen_daily_rate']);
        $this->assertSame(13500, (int) $show['frozen_half_monthly_rate']);
        $this->assertSame(24000, (int) $show['frozen_monthly_rate']);
        // 18 يوماً = 13500 + round(3*13500/15) = 16200
        $this->assertSame(16200, (int) $show['ledger']['contract_total']);
    }

    public function test_showroom_uses_vehicle_stored_package_rates(): void
    {
        $this->postJson('/api/vehicles', [
            'plate_letters' => 'ف ق ك',
            'plate_numbers' => '4444',
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2020,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 3000,
            'daily_rate' => 900,
            'half_monthly_rate' => 12000,
            'monthly_rate' => 20000,
            'default_deposit' => 2500,
            'chassis_number' => 'CHS-PKG-4',
        ])->assertCreated();

        $row = collect($this->getJson('/api/showroom')->assertOk()->json('data.vehicles'))
            ->firstWhere('brand', 'كيا');
        $this->assertNotNull($row);
        $this->assertSame(900, $row['daily_rate']);
        $this->assertSame(12000, $row['half_monthly_rate']);
        $this->assertSame(20000, $row['monthly_rate']);
    }
}
