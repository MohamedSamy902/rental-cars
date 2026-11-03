<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneLoginAndFleetPrepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار ما قبل التسليم',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_login_accepts_phone_number_as_well_as_email(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        User::factory()->create([
            'name' => 'مدير الهاتف',
            'email' => 'phone-admin@rpm.local',
            'phone' => '01000000000',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $this->postJson('/api/login', [
            'login' => '01000000000',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.email', 'phone-admin@rpm.local')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone']]);

        $this->postJson('/api/login', [
            'phone' => '+20 100 000 0000',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('user.phone', '01000000000');

        $this->postJson('/api/login', [
            'email' => 'phone-admin@rpm.local',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_future_window_lists_car_returning_before_booking_start(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]));

        $customer = Customer::query()->create([
            'full_name' => 'عميل مستقبلي',
            'phone' => '01099998888',
            'birth_date' => '1991-01-01',
            'license_number' => 'LIC-FUT',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'FUT-1',
            'plate' => 'ف ت 11',
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 12000,
            'daily_rate' => 600,
            'default_deposit' => 2500,
            'is_active' => true,
        ]);

        // Currently rented, returns in 2 days — should be available for a booking starting in 5 days.
        Contract::query()->create([
            'number' => 'C-NOW',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(3),
            'planned_ends_at' => now()->addDays(2)->setTime(10, 0),
            'frozen_daily_rate' => 600,
            'frozen_agreed_deposit' => 2500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
        ]);

        $from = now()->addDays(5)->startOfDay()->toIso8601String();
        $to = now()->addDays(8)->endOfDay()->toIso8601String();

        $recommend = $this->getJson('/api/vehicles/recommend?from='.urlencode($from).'&to='.urlencode($to))
            ->assertOk();
        $ids = collect($recommend->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($vehicle->id), 'Vehicle returning before future window must be recommendable');

        $available = $this->getJson('/api/vehicles?status=available&from='.urlencode($from).'&to='.urlencode($to))
            ->assertOk();
        $this->assertTrue(
            collect($available->json('data'))->pluck('id')->contains($vehicle->id),
            'Vehicle returning before future window must appear in available list'
        );
    }

    public function test_daily_prep_covers_today_tomorrow_and_next_three_days(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]));

        $customer = Customer::query()->create([
            'full_name' => 'عميل التجهيز',
            'phone' => '01077776666',
            'birth_date' => '1990-02-02',
            'license_number' => 'LIC-PREP',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'PREP-1',
            'plate' => 'ت ج 22',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 8000,
            'daily_rate' => 550,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        Contract::query()->create([
            'number' => 'C-PICKUP',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->addDay()->setTime(9, 0),
            'planned_ends_at' => now()->addDays(4)->setTime(9, 0),
            'frozen_daily_rate' => 550,
            'frozen_agreed_deposit' => 2000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
        ]);

        $activeVehicle = Vehicle::query()->create([
            'code' => 'PREP-2',
            'plate' => 'ت ج 33',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 15000,
            'daily_rate' => 500,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
        Contract::query()->create([
            'number' => 'C-RETURN',
            'customer_id' => $customer->id,
            'vehicle_id' => $activeVehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->setTime(18, 0),
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 2000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'workshop' => 'غسيل وتجهيز',
            'opened_on' => now()->toDateString(),
            'odometer' => 8000,
            'status' => 'open',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addDays(2)->toDateString(),
        ]);

        $prep = $this->getJson('/api/fleet/daily-prep?days=5')->assertOk();
        $prep->assertJsonPath('data.horizon_days', 5);
        $this->assertCount(5, $prep->json('data.days'));
        $this->assertSame('اليوم', $prep->json('data.days.0.label'));
        $this->assertSame('غداً', $prep->json('data.days.1.label'));
        $this->assertGreaterThanOrEqual(1, (int) $prep->json('data.days.0.counts.returns'));
        $this->assertGreaterThanOrEqual(1, (int) $prep->json('data.days.1.counts.pickups'));
        $this->assertGreaterThanOrEqual(1, (int) $prep->json('data.totals.maintenance'));

        $dash = $this->getJson('/api/dashboard')->assertOk();
        $dash->assertJsonStructure(['fleet_prep' => ['days', 'totals', 'horizon_days']]);
        $this->assertCount(5, $dash->json('fleet_prep.days'));
    }
}
