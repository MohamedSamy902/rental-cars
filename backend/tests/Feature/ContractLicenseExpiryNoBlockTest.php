<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractLicenseExpiryNoBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار رخصة',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => true,
                'block_expired_vehicle_docs' => true,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $admin->id,
            'is_active' => true,
            'name' => 'مدير الرخص',
        ]));
    }

    public function test_create_and_approve_when_customer_and_vehicle_licenses_expire_mid_contract(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل رخصة قصيرة',
            'phone' => '01055550101',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-SHORT',
            'license_expires_on' => now()->addDays(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'LIC-1',
            'plate' => 'ر خ ص 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);
        VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => 'رخصة تسيير',
            'expires_on' => now()->addDays(3)->toDateString(),
            'file_path' => 'demo/licenses/short.pdf',
            'notes' => 'تنتهي أثناء العقد',
        ]);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(20)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_approve_when_customer_license_already_expired(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل رخصة منتهية',
            'phone' => '01055550102',
            'birth_date' => '1988-01-01',
            'license_number' => 'LIC-PAST',
            'license_expires_on' => now()->subDays(10)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'LIC-2',
            'plate' => 'ر خ ص 2',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 600,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(5)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }
}
