<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShowroomCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'مركز آر بي إم للتأجير',
            'feature_flags' => ['showroom' => true],
            'operational' => [
                'print_phone' => '01125253888',
                'showroom_weekly_factor' => 6,
                'showroom_monthly_factor' => 22,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_showroom_rates_use_commercial_day_factors_and_phone(): void
    {
        Vehicle::query()->create([
            'code' => 'SHR-1',
            'plate' => 'أ ب ج 9999',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2018,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'transmission' => 'مانيوال',
            'odometer' => 1000,
            'daily_rate' => 1475,
            'default_deposit' => 4700,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/showroom')->assertOk();
        $res->assertJsonPath('data.center_name', 'مركز آر بي إم للتأجير');
        $res->assertJsonPath('data.center_phone', '01125253888');

        $row = collect($res->json('data.vehicles'))->firstWhere('brand', 'نيسان');
        $this->assertNotNull($row);
        $this->assertSame('نيسان', $row['brand']);
        $this->assertSame('صني', $row['model']);
        $this->assertSame(1475, $row['daily_rate']);
        $this->assertSame(1475 * 6, $row['weekly_rate']);
        $this->assertSame(1475 * 22, $row['monthly_rate']);
        $this->assertSame(4700, $row['default_deposit']);
    }

    public function test_repair_demo_vehicles_fixes_mg_sunny(): void
    {
        $v = Vehicle::query()->create([
            'code' => 'MG-035',
            'plate' => 'س ص ع 3500',
            'brand' => 'MG',
            'model' => 'صني',
            'year' => 2018,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1475,
            'default_deposit' => 4700,
            'is_active' => true,
            'notes' => 'بذرة ميجا — حالة خاصة',
        ]);

        $this->artisan('fleet:repair-demo-vehicles')->assertSuccessful();

        $v->refresh();
        $this->assertNotSame('صني', $v->model);
        $this->assertTrue(\App\Support\FleetCatalog::modelBelongsToBrand($v->brand, $v->model));
    }
}
