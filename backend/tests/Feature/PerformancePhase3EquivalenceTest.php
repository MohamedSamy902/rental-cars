<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\ProfitabilitySnapshotService;
use App\Support\PermissionCatalog;
use App\Support\PerfReadCache;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-3: فهارس + كاش قراءة + برهان ملخص ربحية مشتق (بلا تغيير نتائج افتراضي).
 */
class PerformancePhase3EquivalenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        Cache::flush();
    }

    public function test_perf_read_indexes_exist_on_sqlite_or_mysql(): void
    {
        $indexes = collect(Schema::getIndexes('contracts'))->pluck('name')->all();
        $this->assertTrue(
            in_array('contracts_status_starts_at_idx', $indexes, true)
            || collect($indexes)->contains(fn ($n) => str_contains((string) $n, 'status_starts_at')),
            'contracts status+starts_at index missing'
        );

        $wo = collect(Schema::getIndexes('work_orders'))->pluck('name')->all();
        $this->assertTrue(
            in_array('work_orders_vehicle_status_maint_idx', $wo, true)
            || collect($wo)->contains(fn ($n) => str_contains((string) $n, 'vehicle_status_maint')),
            'work_orders vehicle+status+maint index missing'
        );

        $this->assertTrue(Schema::hasTable('vehicle_profit_month_summaries'));
    }

    public function test_profit_month_summary_matches_live_aggregation(): void
    {
        $month = now()->startOfMonth();
        $v1 = $this->makeVehicle('S1');
        $v2 = $this->makeVehicle('S2');
        $customer = $this->makeCustomer();

        Contract::query()->create([
            'number' => 'CNT-P3-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $v1->id,
            'status' => 'completed',
            'starts_at' => $month->copy()->addDays(1),
            'planned_ends_at' => $month->copy()->addDays(5),
            'actual_ends_at' => $month->copy()->addDays(5),
            'period_rent' => 4000,
            'frozen_daily_rate' => 800,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $v1->id,
            'opened_on' => $month->copy()->addDays(2)->toDateString(),
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'odometer' => 1000,
            'parts_cost' => 200,
            'labor_cost' => 100,
        ]);
        Expense::query()->create([
            'title' => 'مصروف P3',
            'category' => 'other',
            'amount' => 150,
            'spent_on' => $month->copy()->addDays(3)->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $v2->id,
        ]);

        $svc = app(ProfitabilitySnapshotService::class);
        $svc->rebuildMonth($month);
        $diffs = $svc->diffAgainstLive($month);

        $this->assertSame([], $diffs, 'snapshot must equal live: '.implode('; ', $diffs));
    }

    public function test_attention_cache_returns_same_payload_and_invalidates(): void
    {
        config(['rpm.perf_cache_in_tests' => true, 'rpm.perf_read_cache' => true]);
        Cache::flush();

        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle('AT');
        Contract::query()->create([
            'number' => 'CNT-P3-ATT',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(4),
            'period_rent' => 1000,
            'frozen_daily_rate' => 500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ]);

        $a = $this->getJson('/api/attention')->assertOk()->json('data');
        $b = $this->getJson('/api/attention')->assertOk()->json('data');
        $this->assertSame($a, $b);
        $this->assertSame(1, (int) $a['pending_approvals']);

        // إبطال عبر كتابة عقد
        Contract::query()->where('number', 'CNT-P3-ATT')->update(['status' => 'approved']);
        PerfReadCache::bump();

        $c = $this->getJson('/api/attention')->assertOk()->json('data');
        $this->assertSame(0, (int) $c['pending_approvals']);
    }

    public function test_query_budgets_on_critical_list_endpoints(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

        $customer = $this->makeCustomer();
        for ($i = 0; $i < 8; $i++) {
            $v = $this->makeVehicle('B'.$i);
            Contract::query()->create([
                'number' => 'CNT-P3-B'.$i,
                'customer_id' => $customer->id,
                'vehicle_id' => $v->id,
                'status' => $i % 2 === 0 ? 'active' : 'pending',
                'starts_at' => now()->subDays(2),
                'planned_ends_at' => now()->addDays(5),
                'period_rent' => 2000,
                'frozen_daily_rate' => 500,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 1000,
                'discount' => 0,
                'other_fees' => 0,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/contracts?per_page=50')->assertOk();
        $contractsQ = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(25, $contractsQ, "contracts list queries={$contractsQ}");

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/contracts?view=calendar&per_page=200')->assertOk();
        $calQ = count(DB::getQueryLog());
        $calBytes = strlen($this->getJson('/api/contracts?view=calendar&per_page=200')->getContent());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(20, $calQ, "calendar queries={$calQ}");
        $this->assertLessThan(100_000, $calBytes, "calendar bytes={$calBytes}");

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/vehicles?per_page=50')->assertOk();
        $vehQ = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(25, $vehQ, "vehicles queries={$vehQ}");
    }

    private function makeCustomer(): Customer
    {
        return Customer::query()->create([
            'full_name' => 'عميل P3',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'L-P3-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function makeVehicle(string $suffix): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'P3-'.$suffix.'-'.uniqid(),
            'plate' => 'P3 '.$suffix.' '.random_int(100, 999),
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
