<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViolationPhotosTest extends TestCase
{
    use RefreshDatabase;

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
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_store_accepts_multiple_photo_paths(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل صور',
            'phone' => '01001112233',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-PH',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-PH-1',
            'plate' => 'ط ي 909',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2022,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'odometer_source' => 'manual',
            'daily_rate' => 900,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'number' => 'CNT-PH-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
            'approved_days' => 4,
            'frozen_daily_rate' => 900,
            'frozen_agreed_deposit' => 1500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'odometer_out' => 1000,
        ]);

        $this->postJson('/api/violations', [
            'contract_id' => $contract->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 200,
            'photo_paths' => ['/media/11/file', '/media/12/file', '/media/13/file'],
        ])->assertCreated()
            ->assertJsonPath('data.photo_path', '/media/11/file')
            ->assertJsonPath('data.photo_paths.0', '/media/11/file')
            ->assertJsonPath('data.photo_paths.2', '/media/13/file');

        $this->assertDatabaseHas('violations', [
            'vehicle_id' => $vehicle->id,
            'contract_id' => $contract->id,
            'amount' => 200,
            'photo_path' => '/media/11/file',
        ]);
    }
}
