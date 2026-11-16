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
        $customer = Customer::query()->create([
            'full_name' => 'عميل تجميد',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'FZ-1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'FZ-1',
            'plate' => 'ف ز 1',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

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
}
