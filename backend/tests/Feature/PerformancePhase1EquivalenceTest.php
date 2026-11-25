<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\ProfitabilityService;
use App\Services\Contracts\RentalProgressService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase-1 perf: same business outputs, fewer queries.
 */
class PerformancePhase1EquivalenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
    }

    public function test_vehicle_status_batch_matches_per_row_exists(): void
    {
        $availability = app(VehicleAvailabilityService::class);
        $customer = $this->makeCustomer();

        $available = $this->makeVehicle('AV');
        $rented = $this->makeVehicle('RN');
        $reserved = $this->makeVehicle('RS');
        $maintManual = $this->makeVehicle('MM', 'maintenance');
        $maintWo = $this->makeVehicle('MW');

        $this->makeContract($rented, $customer, [
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
        ]);
        $this->makeContract($reserved, $customer, [
            'status' => 'approved',
            'starts_at' => now()->subHour(),
            'planned_ends_at' => now()->addDays(2),
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $maintWo->id,
            'puts_in_maintenance' => true,
            'status' => 'open',
            'opened_on' => now()->toDateString(),
            'odometer' => 1000,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        $active = Contract::query()->where('status', 'active')->pluck('vehicle_id')->flip();
        $reservedIds = Contract::query()
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('planned_ends_at', '>=', now())
            ->pluck('vehicle_id')
            ->flip();
        $blockingWo = WorkOrder::query()
            ->where('puts_in_maintenance', true)
            ->whereIn('status', ['open', 'in_progress'])
            ->pluck('vehicle_id')
            ->flip();

        foreach ([$available, $rented, $reserved, $maintManual, $maintWo] as $v) {
            $batched = $availability->statusOnDate([
                'manual_status' => $v->manual_status,
                'has_active_handover' => $active->has($v->id),
                'has_approved_covering_date' => $reservedIds->has($v->id),
                'open_work_order_blocks' => $v->manual_status === 'maintenance' || $blockingWo->has($v->id),
            ]);
            $legacy = $availability->statusOnDate([
                'manual_status' => $v->manual_status,
                'has_active_handover' => $v->contracts()->where('status', 'active')->exists(),
                'has_approved_covering_date' => $v->contracts()
                    ->where('status', 'approved')
                    ->where('starts_at', '<=', now())
                    ->where('planned_ends_at', '>=', now())
                    ->exists(),
                'open_work_order_blocks' => $v->manual_status === 'maintenance'
                    || WorkOrder::query()
                        ->where('vehicle_id', $v->id)
                        ->where('puts_in_maintenance', true)
                        ->whereIn('status', ['open', 'in_progress'])
                        ->exists(),
            ]);
            $this->assertSame($legacy, $batched, "vehicle {$v->id} status mismatch");
        }
    }

    public function test_vehicles_index_stays_under_query_budget(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($admin);

        $customer = $this->makeCustomer();
        for ($i = 0; $i < 12; $i++) {
            $v = $this->makeVehicle('V'.$i);
            if ($i < 4) {
                $this->makeContract($v, $customer, [
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'planned_ends_at' => now()->addDays(5),
                ]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/vehicles?per_page=50')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(40, $count, "vehicles index ran {$count} queries");
    }

    public function test_progress_preload_matches_per_contract_query(): void
    {
        $svc = app(RentalProgressService::class);
        $customer = $this->makeCustomer();
        $vehicle = $this->makeVehicle('PG');
        $contract = $this->makeContract($vehicle, $customer, [
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->addDays(8),
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'puts_in_maintenance' => true,
            'status' => 'in_progress',
            'opened_on' => now()->subDay()->toDateString(),
            'odometer' => 1000,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        $legacy = $svc->forContract($contract->fresh(['customer', 'vehicle']));
        $map = $svc->openMaintenanceByVehicleIds([$vehicle->id]);
        $batched = $svc->forContract($contract->fresh(['customer', 'vehicle']), null, $map);

        $this->assertSame($legacy['percent'], $batched['percent']);
        $this->assertSame($legacy['tone'], $batched['tone']);
        $this->assertSame($legacy['limiting_factor'], $batched['limiting_factor']);
        $this->assertSame($legacy['markers'], $batched['markers']);
        $this->assertSame($legacy['remaining_label'], $batched['remaining_label']);
    }

    public function test_profitability_month_snapshot_math(): void
    {
        $month = Carbon::now()->startOfMonth();
        $v1 = $this->makeVehicle('P1');
        $v2 = $this->makeVehicle('P2');
        $customer = $this->makeCustomer();

        $this->makeContract($v1, $customer, [
            'status' => 'completed',
            'period_rent' => 5000,
            'starts_at' => $month->copy()->addDays(1),
            'planned_ends_at' => $month->copy()->addDays(5),
            'actual_ends_at' => $month->copy()->addDays(5),
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $v1->id,
            'parts_cost' => 200,
            'labor_cost' => 100,
            'opened_on' => $month->copy()->addDays(2)->toDateString(),
            'closed_on' => $month->copy()->addDays(2)->toDateString(),
            'status' => 'closed',
            'odometer' => 12000,
            'puts_in_maintenance' => false,
        ]);
        Expense::query()->create([
            'title' => 'مصروف 1',
            'category' => 'تشغيل',
            'amount' => 500,
            'spent_on' => $month->copy()->addDays(3)->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $v1->id,
        ]);
        Expense::query()->create([
            'title' => 'مصروف 2',
            'category' => 'تشغيل',
            'amount' => 50,
            'spent_on' => $month->copy()->addDays(4)->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $v2->id,
        ]);

        $snap = app(ProfitabilityService::class)->monthSnapshot($month);

        $this->assertSame(5000, $snap['vehicles'][$v1->id]['revenue']);
        $this->assertSame(300, $snap['vehicles'][$v1->id]['maintenance']);
        $this->assertSame(500, $snap['vehicles'][$v1->id]['expenses']);
        $this->assertSame(4200, $snap['vehicles'][$v1->id]['operating_profit']);
        $this->assertSame(0, $snap['vehicles'][$v2->id]['revenue']);
        $this->assertSame(50, $snap['vehicles'][$v2->id]['expenses']);
        $this->assertSame(5000, $snap['revenue']);
        $this->assertSame(300, $snap['maintenance']);
        $this->assertSame(550, $snap['expenses']);
        $this->assertSame(5000 - 300 - 550, $snap['operating_profit']);
    }

    private function makeCustomer(): Customer
    {
        return Customer::query()->create([
            'full_name' => 'عميل أداء',
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'L-'.uniqid(),
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function makeVehicle(string $suffix, ?string $manualStatus = null): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'PF-'.$suffix.'-'.uniqid(),
            'plate' => 'PF '.$suffix.' '.random_int(100, 999),
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
            'manual_status' => $manualStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeContract(Vehicle $vehicle, Customer $customer, array $attrs): Contract
    {
        return Contract::query()->create([
            'number' => 'CNT-PF-'.uniqid(),
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => $attrs['status'] ?? 'active',
            'starts_at' => $attrs['starts_at'],
            'planned_ends_at' => $attrs['planned_ends_at'],
            'actual_ends_at' => $attrs['actual_ends_at'] ?? null,
            'period_rent' => $attrs['period_rent'] ?? 0,
            'frozen_daily_rate' => 500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 1000,
            'discount' => 0,
            'other_fees' => 0,
        ])->fresh(['customer', 'vehicle']);
    }
}
