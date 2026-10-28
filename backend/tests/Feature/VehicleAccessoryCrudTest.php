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

class VehicleAccessoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicle;

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
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'V-ACC-1',
            'plate' => 'س ص ع 9999',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'odometer_source' => 'manual',
            'daily_rate' => 30000,
            'default_deposit' => 50000,
            'rental_priority' => 50,
            'is_active' => true,
        ]);
    }

    public function test_accessory_create_update_delete(): void
    {
        $create = $this->postJson('/api/vehicles/'.$this->vehicle->id.'/accessories', [
            'name' => 'مثلث عاكس',
            'default_qty' => 2,
            'required_each_rental' => true,
            'notes' => 'في الصندوق',
        ], ['Idempotency-Key' => 'acc-create-1']);

        $create->assertCreated()->assertJsonPath('data.name', 'مثلث عاكس');
        $id = $create->json('data.id');

        $update = $this->putJson('/api/vehicles/'.$this->vehicle->id.'/accessories/'.$id, [
            'name' => 'مثلث عاكس كبير',
            'default_qty' => 1,
            'required_each_rental' => false,
            'is_active' => true,
        ], ['Idempotency-Key' => 'acc-update-1']);

        $update->assertOk()
            ->assertJsonPath('data.name', 'مثلث عاكس كبير')
            ->assertJsonPath('data.default_qty', 1)
            ->assertJsonPath('data.required_each_rental', false);

        $this->deleteJson('/api/vehicles/'.$this->vehicle->id.'/accessories/'.$id, [], [
            'Idempotency-Key' => 'acc-del-1',
        ])->assertOk();

        $this->assertDatabaseMissing('vehicle_accessories', ['id' => $id]);
    }

    public function test_dossier_includes_charts_and_extra_fields(): void
    {
        $this->vehicle->update([
            'color' => 'أبيض',
            'transmission' => 'أوتوماتيك',
            'chassis_number' => 'CHS-1',
            'seats' => 5,
        ]);

        $res = $this->getJson('/api/vehicles/'.$this->vehicle->id.'/dossier');
        $res->assertOk()
            ->assertJsonPath('data.vehicle.color', 'أبيض')
            ->assertJsonPath('data.vehicle.transmission', 'أوتوماتيك')
            ->assertJsonPath('data.vehicle.chassis_number', 'CHS-1')
            ->assertJsonStructure([
                'data' => [
                    'charts' => [
                        'contracts_by_status',
                        'expenses_by_month',
                        'maintenance_by_month',
                        'revenue_by_month',
                    ],
                ],
            ]);
    }
}
