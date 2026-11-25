<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\ProfitabilityService;
use App\Services\Accounting\ProfitabilitySnapshotService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformancePhase4EquivalenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        Cache::flush();
        config(['rpm.perf_cache_in_tests' => false, 'rpm.use_profit_month_summaries' => true]);
    }

    public function test_dashboard_kpis_stable_with_shared_loads(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

        $customer = $this->makeCustomer();
        $v1 = $this->makeVehicle('D1');
        $v2 = $this->makeVehicle('D2');
        Contract::query()->create([
            'number' => 'CNT-P4-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $v1->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(2),
            'period_rent' => 2000,
            'frozen_daily_rate' => 500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $v2->id,
            'opened_on' => now()->toDateString(),
            'status' => 'open',
            'puts_in_maintenance' => true,
            'odometer' => 1000,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);
        InventoryItem::query()->create([
            'code' => 'P4-I1',
            'name' => 'زيت',
            'category' => 'صيانة',
            'unit' => 'لتر',
            'reorder_level' => 10,
            'is_active' => true,
        ]);

        $res = $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(1, (int) $res->json('kpis.rented'));
        $this->assertSame(1, (int) $res->json('kpis.open_work_orders'));
        $this->assertGreaterThanOrEqual(1, (int) $res->json('kpis.low_stock'));
        $this->assertIsArray($res->json('important_tasks'));
        $this->assertIsArray($res->json('fleet_prep'));
    }

    public function test_dashboard_query_budget_cold(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));
        $this->makeVehicle('QB');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/dashboard')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(35, $n, "dashboard cold queries={$n}");
    }

    public function test_profit_snapshot_preferred_when_present(): void
    {
        $v = $this->makeVehicle('PS');
        $customer = $this->makeCustomer();
        $month = now()->startOfMonth();
        Contract::query()->create([
            'number' => 'CNT-P4-PS',
            'customer_id' => $customer->id,
            'vehicle_id' => $v->id,
            'status' => 'completed',
            'starts_at' => $month->copy()->addDay(),
            'planned_ends_at' => $month->copy()->addDays(3),
            'actual_ends_at' => $month->copy()->addDays(3),
            'period_rent' => 1500,
            'frozen_daily_rate' => 500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ]);

        $snap = app(ProfitabilitySnapshotService::class);
        $snap->rebuildMonth($month);
        $this->assertSame([], $snap->diffAgainstLive($month));

        $fromService = app(ProfitabilityService::class)->monthSnapshot($month);
        $this->assertSame(1500, (int) $fromService['vehicles'][$v->id]['revenue']);
    }

    public function test_inventory_search_filters(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));
        InventoryItem::query()->create([
            'code' => 'OIL-1', 'name' => 'زيت محرك', 'category' => 'صيانة', 'unit' => 'لتر', 'reorder_level' => 1, 'is_active' => true,
        ]);
        InventoryItem::query()->create([
            'code' => 'FLT-1', 'name' => 'فلتر هواء', 'category' => 'صيانة', 'unit' => 'قطعة', 'reorder_level' => 1, 'is_active' => true,
        ]);

        $res = $this->getJson('/api/inventory?search=زيت&per_page=30')->assertOk()->json('data');
        $this->assertCount(1, $res);
        $this->assertSame('OIL-1', $res[0]['code']);
    }

    private function makeCustomer(): Customer
    {
        return Customer::query()->create([
            'full_name' => 'عميل P4',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'L-P4-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function makeVehicle(string $suffix): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'P4-'.$suffix.'-'.uniqid(),
            'plate' => 'P4 '.$suffix.' '.random_int(100, 999),
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);
    }
}
