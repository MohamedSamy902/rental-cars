<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * قوائم العقود/العربيات: ٢٠ صف/صفحة · البحث على السيرفر عبر كل الصفحات.
 */
class ListPageSizeAndGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_vehicles_default_page_size_is_20_and_search_spans_all_pages(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك البحث النادر',
            'phone' => '01000000001',
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 25; $i++) {
            Vehicle::query()->create([
                'code' => sprintf('PG-%02d', $i),
                'plate' => sprintf('PL %02d', $i),
                'brand' => 'تويوتا',
                'model' => 'كورولا',
                'year' => 2024,
                'category' => 'سيدان',
                'fuel_type' => 'بنزين',
                'odometer' => 1000 + $i,
                'daily_rate' => 500,
                'default_deposit' => 1000,
                'is_active' => true,
            ]);
        }

        // عربية في «نهاية» القائمة (أحدث id) لكن البحث يجب أن يجدها من أي صفحة.
        $needle = Vehicle::query()->create([
            'code' => 'NEEDLE-VH',
            'plate' => 'NDL 99',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 600,
            'default_deposit' => 1000,
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $page1 = $this->getJson('/api/vehicles')->assertOk();
        $this->assertCount(20, $page1->json('data'));
        $this->assertGreaterThanOrEqual(26, (int) $page1->json('meta.total'));
        $this->assertGreaterThanOrEqual(2, (int) $page1->json('meta.last_page'));

        // حتى لو طلبنا صفحة ٢، البحث يعيد المطابقة من كل الأسطول (صفحة ١ من نتائج البحث).
        $all = $this->getJson('/api/vehicles?search=NEEDLE&per_page=20')->assertOk();
        $this->assertTrue(collect($all->json('data'))->contains('id', $needle->id));
        $this->assertSame(1, (int) $all->json('meta.total'));

        $byOwner = $this->getJson('/api/vehicles?search='.urlencode('مالك البحث النادر'))->assertOk();
        $this->assertTrue(collect($byOwner->json('data'))->contains('id', $needle->id));
    }

    public function test_contracts_default_page_size_is_20_and_search_spans_fleet(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل البحث',
            'phone' => '01099887766',
            'birth_date' => '1990-01-01',
            'license_number' => 'LX-PG',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'CNT-VH',
            'plate' => 'CNT 77',
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2022,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 400,
            'default_deposit' => 500,
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 22; $i++) {
            \App\Models\Contract::query()->create([
                'number' => sprintf('PG-C-%02d', $i),
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'status' => 'pending',
                'starts_at' => now()->addDays($i),
                'planned_ends_at' => now()->addDays($i + 2),
                'frozen_daily_rate' => 400,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 500,
            ]);
        }

        $needle = \App\Models\Contract::query()->create([
            'number' => 'NEEDLE-CNT',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDays(40),
            'planned_ends_at' => now()->addDays(42),
            'frozen_daily_rate' => 400,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 500,
        ]);

        $page1 = $this->getJson('/api/contracts')->assertOk();
        $this->assertCount(20, $page1->json('data'));
        $this->assertGreaterThanOrEqual(2, (int) $page1->json('meta.last_page'));

        $byNumber = $this->getJson('/api/contracts?search=NEEDLE-CNT')->assertOk();
        $this->assertTrue(collect($byNumber->json('data'))->contains('id', $needle->id));
        $this->assertSame(1, (int) $byNumber->json('meta.total'));

        $byPlate = $this->getJson('/api/contracts?search=CNT+77')->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $byPlate->json('meta.total'));

        $byPhone = $this->getJson('/api/contracts?search=01099887766')->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $byPhone->json('meta.total'));
    }
}
