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

class SmartSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار بحث',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_vehicle_search_matches_plate_letters_without_spaces_or_hamza(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'SRCH-1',
            'plate' => 'أ ب ج 1234',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        foreach (['ابج', 'ا ب ج', 'أبج', '1234', 'ابج1234'] as $q) {
            $ids = collect($this->getJson('/api/vehicles?search='.urlencode($q).'&per_page=50')->assertOk()->json('data'))
                ->pluck('id')
                ->all();
            $this->assertContains($vehicle->id, $ids, "فشل البحث عن اللوحة بـ: {$q}");
        }
    }

    public function test_customer_search_matches_name_without_hamza(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'أحمد إبراهيم',
            'phone' => '01012345678',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-SRCH',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        foreach (['احمد', 'ابراهيم', 'احمد ابراهيم'] as $q) {
            $ids = collect($this->getJson('/api/customers?search='.urlencode($q).'&per_page=50')->assertOk()->json('data'))
                ->pluck('id')
                ->all();
            $this->assertContains($customer->id, $ids, "فشل البحث عن الاسم بـ: {$q}");
        }
    }
}
