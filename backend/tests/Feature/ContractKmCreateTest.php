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

class ContractKmCreateTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار كم',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
                'default_daily_km' => 180,
                'excess_km_rate' => 7,
            ],
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل كم',
            'phone' => '01001112233',
            'birth_date' => '1992-03-03',
            'license_number' => 'LIC-KM',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'KM-1',
            'plate' => 'KM 100',
            'brand' => 'شيري',
            'model' => 'تيجو',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 12000,
            'daily_rate' => 900,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function actingWith(array $keys): User
    {
        $role = Role::query()->create([
            'name' => 'km_'.md5(implode(',', $keys).microtime(true)),
            'label' => 'اختبار كم',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $keys)->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_create_accepts_km_fields_without_price_override(): void
    {
        $this->actingWith(['contracts.view', 'contracts.create']);

        $create = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addDays(2)->toIso8601String(),
            'daily_km_allowance' => 250,
            'excess_km_rate' => 9,
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(3)->toDateString(),
        ])->assertCreated();

        $this->assertSame(250, (int) $create->json('data.frozen_daily_km_allowance'));
        $this->assertSame(9, (int) $create->json('data.frozen_excess_km_rate'));
        // Without override, daily rate stays from vehicle.
        $this->assertSame(900, (int) $create->json('data.frozen_daily_rate'));
    }

    public function test_create_uses_settings_defaults_when_km_omitted(): void
    {
        $this->actingWith(['contracts.view', 'contracts.create']);

        $create = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDays(5)->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(6)->startOfDay()->toIso8601String(),
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $this->assertSame(180, (int) $create->json('data.frozen_daily_km_allowance'));
        $this->assertSame(7, (int) $create->json('data.frozen_excess_km_rate'));
    }
}
