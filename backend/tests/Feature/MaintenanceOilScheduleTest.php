<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenanceOilScheduleTest extends TestCase
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
            'center_name' => 'اختبار زيت',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'OIL-1',
            'plate' => 'ز ي 1001',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 50500,
            'daily_rate' => 400,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);
    }

    public function test_oil_view_returns_last_and_next_oil_change(): void
    {
        $order = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'workshop' => 'ورشة',
            'opened_on' => '2026-10-01',
            'opened_at' => '2026-10-01 11:00:00',
            'odometer' => 48000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addDays(3)->toDateString(),
            'next_service_odometer' => null,
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $res = $this->getJson('/api/work-orders?view=oil&tab=oil&per_page=50')
            ->assertOk()
            ->json();

        $row = collect($res['data'])->firstWhere('vehicle_id', $this->vehicle->id);
        $this->assertNotNull($row);
        $this->assertSame('2026-10-01', $row['last_oil_change_on']);
        $this->assertSame(now()->addDays(3)->toDateString(), $row['next_oil_on']);
        $this->assertTrue($row['oil_due_soon']);
        $this->assertArrayNotHasKey('parts_cost', $row);
        $this->assertGreaterThanOrEqual(1, (int) ($res['summary']['oil_due_soon'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($res['summary']['with_oil_history'] ?? 0));
    }

    public function test_work_order_list_is_maintenance_lean_without_money(): void
    {
        $order = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'workshop' => 'ورشة',
            'opened_on' => now()->toDateString(),
            'opened_at' => now(),
            'odometer' => 50000,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'parts_cost' => 900,
            'labor_cost' => 100,
            'next_service_on' => now()->addMonths(2)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 900,
        ]);

        $row = collect($this->getJson('/api/work-orders?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['has_oil_change']);
        $this->assertContains('زيت موتور', $row['items']);
        $this->assertArrayNotHasKey('parts_cost', $row);
        $this->assertArrayNotHasKey('labor_cost', $row);
    }

    public function test_upcoming_tab_filters_due_oil_only(): void
    {
        $due = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => now()->subMonths(3)->toDateString(),
            'opened_at' => now()->subMonths(3),
            'odometer' => 40000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addDay()->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $due->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $other = Vehicle::query()->create([
            'code' => 'OIL-2',
            'plate' => 'ز ي 1002',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 400,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);
        $far = WorkOrder::query()->create([
            'vehicle_id' => $other->id,
            'opened_on' => now()->subMonth()->toDateString(),
            'opened_at' => now()->subMonth(),
            'odometer' => 19000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(4)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $far->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $ids = collect($this->getJson('/api/work-orders?view=oil&tab=upcoming&per_page=50')->assertOk()->json('data'))
            ->pluck('vehicle_id')
            ->all();

        $this->assertContains($this->vehicle->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_oil_view_can_filter_single_vehicle(): void
    {
        $order = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => '2026-09-01',
            'opened_at' => '2026-09-01 10:00:00',
            'odometer' => 45000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(1)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $data = $this->getJson('/api/work-orders?view=oil&tab=oil&vehicle_id='.$this->vehicle->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($this->vehicle->id, (int) $data[0]['vehicle_id']);
        $this->assertSame('2026-09-01', $data[0]['last_oil_change_on']);
    }

    public function test_vehicle_due_soon_lists_upcoming_work_orders(): void
    {
        $due = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => '2026-10-01',
            'opened_at' => '2026-10-01 10:00:00',
            'odometer' => 49000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addDays(2)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $due->id,
            'description' => 'فلتر هوا',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $later = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => '2026-09-01',
            'opened_at' => '2026-09-01 10:00:00',
            'odometer' => 47000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(4)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $later->id,
            'description' => 'إطارات',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $ids = collect(
            $this->getJson('/api/work-orders?vehicle_id='.$this->vehicle->id.'&due_soon=1&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($due->id, $ids);
        $this->assertNotContains($later->id, $ids);
    }

    public function test_has_oil_filters_motor_oil_work_orders(): void
    {
        $oil = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => '2026-08-01',
            'opened_at' => '2026-08-01 10:00:00',
            'odometer' => 45000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(1)->toDateString(),
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $oil->id,
            'description' => 'زيت موتور',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $other = WorkOrder::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'opened_on' => '2026-07-01',
            'opened_at' => '2026-07-01 10:00:00',
            'odometer' => 43000,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'next_service_odometer' => 55000,
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $other->id,
            'description' => 'فلتر تكيف',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $ids = collect(
            $this->getJson('/api/work-orders?vehicle_id='.$this->vehicle->id.'&has_oil=1&per_page=50')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $this->assertContains($oil->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }
}
