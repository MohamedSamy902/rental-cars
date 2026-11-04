<?php

namespace Tests\Feature;

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

        $this->postJson('/api/violations', [
            'vehicle_id' => $vehicle->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 200,
            'photo_paths' => ['/media/11/file', '/media/12/file', '/media/13/file'],
        ])->assertCreated()
            ->assertJsonPath('data.photo_path', '/media/11/file')
            ->assertJsonPath('data.photo_paths.0', '/media/11/file')
            ->assertJsonPath('data.photo_paths.2', '/media/13/file')
            ->assertJsonMissingPath('data.location');

        $this->assertDatabaseHas('violations', [
            'vehicle_id' => $vehicle->id,
            'amount' => 200,
            'photo_path' => '/media/11/file',
        ]);
    }
}
