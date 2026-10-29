<?php

namespace Tests\Feature;

use App\Models\ConsumablePartTemplate;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalaryAdvance;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBattery;
use App\Models\VehicleDowntimeDay;
use App\Models\VehicleTire;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Services\Accounting\ProfitabilityService;
use App\Services\Fleet\OccupancyService;
use App\Services\Fleet\VehicleAssetService;
use App\Services\Fleet\VehicleRecommendationService;
use App\Services\Operations\EmployeeAssignmentService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class P1SpecRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicleA;

    private Vehicle $vehicleB;

    private Customer $customer;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار P1',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'recommendation_underuse_weight' => 40,
                'recommendation_availability_weight' => 30,
                'recommendation_manual_weight' => 30,
                'tire_life_km' => 40000,
                'battery_life_km' => 60000,
                'lost_income_daily_basis' => 800,
            ],
        ]);
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        app(AccountingService::class)->ensureChartOfAccounts();

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل P1',
            'phone' => '01001112233',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-P1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
            'credit_balance' => 1500,
        ]);

        $this->vehicleA = Vehicle::query()->create([
            'code' => 'P1-A', 'plate' => 'P1 100', 'brand' => 'هيونداي', 'model' => 'إلنترا',
            'year' => 2023, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 12000, 'daily_rate' => 1000, 'default_deposit' => 2000,
            'is_active' => true, 'rental_priority' => 80, 'manual_status' => 'available',
        ]);
        $this->vehicleB = Vehicle::query()->create([
            'code' => 'P1-B', 'plate' => 'P1 200', 'brand' => 'كيا', 'model' => 'سيراتو',
            'year' => 2024, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 4000, 'daily_rate' => 900, 'default_deposit' => 2000,
            'is_active' => true, 'rental_priority' => 20, 'manual_status' => 'available',
        ]);

        $this->employee = Employee::query()->create([
            'name' => 'موظف عهدة',
            'job_title' => 'تشغيل',
            'base_salary' => 5000,
            'status' => 'active',
            'hired_on' => now()->subYear()->toDateString(),
        ]);
    }

    public function test_permission_catalog_includes_granular_hr_keys(): void
    {
        $keys = collect(PermissionCatalog::definitions())->pluck('key');
        foreach (['hr.salaries', 'hr.custody', 'hr.advances', 'hr.expenses'] as $key) {
            $this->assertTrue($keys->contains($key), "missing {$key}");
            $this->assertNotNull(Permission::query()->where('key', $key)->first());
        }
        $this->assertTrue(PermissionCatalog::isSensitive('hr.salaries'));
        $this->assertTrue(PermissionCatalog::isSensitive('hr.custody'));
        $this->assertTrue(PermissionCatalog::isSensitive('hr.advances'));
    }

    public function test_employee_assignment_requires_admin_ack(): void
    {
        $service = app(EmployeeAssignmentService::class);
        $row = $service->assign([
            'employee_id' => $this->employee->id,
            'title' => 'عهدة جهاز تتبّع',
            'assignable_type' => 'manual',
            'notes' => 'اختبار',
        ], $this->admin->id);

        $this->assertSame('pending_ack', $row->status);
        $this->assertNull($row->acknowledged_at);

        $acked = $service->acknowledgeReceipt($row, $this->admin->id);
        $this->assertSame('acknowledged', $acked->status);
        $this->assertNotNull($acked->acknowledged_at);

        $this->postJson('/api/employee-assignments', [
            'employee_id' => $this->employee->id,
            'title' => 'إسناد بيانات عميل',
            'assignable_type' => 'customer',
            'assignable_id' => $this->customer->id,
        ])->assertCreated()->assertJsonPath('data.status', 'pending_ack');

        $id = EmployeeAssignment::query()->latest('id')->value('id');
        $this->postJson("/api/employee-assignments/{$id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged');
    }

    public function test_salary_advances_crud_and_payroll_deduction(): void
    {
        $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 1000,
            'advanced_on' => now()->toDateString(),
            'method' => 'cash',
        ])->assertCreated();

        $this->getJson('/api/salary-advances?year='.now()->year.'&month='.now()->month)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/payroll/draft', [
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('n'),
        ])->assertCreated()->assertJsonPath('data.lines.0.deductions', 1000)
            ->assertJsonPath('data.lines.0.net_amount', 4000)
            ->assertJsonPath('data.lines.0.advances', 1000);

        $runId = (int) \App\Models\PayrollRun::query()->value('id');
        $lineId = (int) \App\Models\PayrollLine::query()->value('id');
        $this->postJson("/api/payroll/{$runId}/approve")->assertOk();

        $this->postJson("/api/payroll/lines/{$lineId}/payout", [
            'amount' => 1500,
            'paid_on' => now()->toDateString(),
            'method' => 'cash',
        ])->assertOk()
            ->assertJsonPath('data.paid_amount', 1500)
            ->assertJsonPath('data.carried_over', 2500);

        $this->postJson("/api/payroll/lines/{$lineId}/payout", [
            'amount' => 2500,
            'paid_on' => now()->toDateString(),
            'method' => 'transfer',
        ])->assertOk()
            ->assertJsonPath('data.paid_amount', 4000)
            ->assertJsonPath('data.carried_over', 0);

        $advId = SalaryAdvance::query()->value('id');
        $this->deleteJson("/api/salary-advances/{$advId}")->assertOk();
    }

    public function test_occupancy_uses_rented_days_over_capacity(): void
    {
        // 10-day active rental overlapping a 10-day period with 2 active vehicles → 10/(2*10)=50%
        $this->makeContract([
            'number' => 'C-P1-OCC',
            'vehicle_id' => $this->vehicleA->id,
            'status' => 'active',
            'starts_at' => now()->startOfMonth(),
            'planned_ends_at' => now()->startOfMonth()->addDays(9)->endOfDay(),
            'period_rent' => 10000,
            'approved_days' => 10,
        ]);

        $from = now()->startOfMonth();
        $to = now()->startOfMonth()->addDays(9);
        $pct = app(OccupancyService::class)->percent($from, $to);
        $this->assertEquals(50.0, $pct);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['occupancy_pct', 'occupancy_pct_precise', 'profitability_compare']);
    }

    public function test_profitability_month_over_month_and_report_endpoints(): void
    {
        $thisMonth = now()->startOfMonth();
        $this->makeContract([
            'number' => 'C-P1-REV',
            'vehicle_id' => $this->vehicleA->id,
            'status' => 'completed',
            'starts_at' => $thisMonth->copy()->addDay(),
            'planned_ends_at' => $thisMonth->copy()->addDays(5),
            'actual_ends_at' => $thisMonth->copy()->addDays(5),
            'period_rent' => 5000,
            'final_total' => 5000,
            'approved_days' => 5,
        ]);
        Expense::query()->create([
            'title' => 'مصروف سيارة',
            'category' => 'تشغيل',
            'amount' => 500,
            'spent_on' => $thisMonth->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $this->vehicleA->id,
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $this->vehicleA->id,
            'status' => 'closed',
            'opened_on' => $thisMonth->copy()->addDays(3)->toDateString(),
            'closed_on' => $thisMonth->copy()->addDays(3)->toDateString(),
            'odometer' => 12000,
            'puts_in_maintenance' => false,
            'parts_cost' => 200,
            'labor_cost' => 100,
        ]);

        $compare = app(ProfitabilityService::class)->monthOverMonth($thisMonth);
        $this->assertArrayHasKey('fleet', $compare);
        $this->assertArrayHasKey('vehicles', $compare);
        $this->assertSame($compare['fleet']['current']['operating_profit'], 5000 - 300 - 500);

        $this->getJson('/api/reports/profitability-compare')->assertOk()
            ->assertJsonPath('data.fleet.current.operating_profit', 4200);
        $this->getJson('/api/reports/trial-balance')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/reports/income-statement')->assertOk()
            ->assertJsonStructure(['data' => ['income', 'expenses', 'net']]);
        $this->getJson('/api/reports/payment-methods')->assertOk()->assertJsonStructure(['data']);
        $this->getJson("/api/reports/customers/{$this->customer->id}/statement")->assertOk()
            ->assertJsonPath('data.credit_balance', 1500)
            ->assertJsonPath('data.customer.credit_balance', 1500);
        $this->getJson('/api/reports/summary')->assertOk()
            ->assertJsonStructure(['data' => ['occupancy_pct', 'fleet']]);
    }

    public function test_vehicle_assets_and_recommendation_weights(): void
    {
        $assets = app(VehicleAssetService::class);
        $tire = $assets->addTire($this->vehicleA, [
            'position' => 'front_right',
            'brand' => 'ميشلان',
            'installed_on' => now()->subMonths(2)->toDateString(),
            'odometer_at_install' => 10000,
        ]);
        $this->assertInstanceOf(VehicleTire::class, $tire);
        $this->assertSame(40000, (int) $tire->expected_life_km);

        $battery = $assets->addBattery($this->vehicleA, [
            'brand' => 'ACDelco',
            'installed_on' => now()->subMonths(1)->toDateString(),
            'odometer_at_install' => 11000,
        ]);
        $this->assertInstanceOf(VehicleBattery::class, $battery);

        $down = $assets->openDowntime($this->vehicleA, [
            'reason' => 'maintenance',
            'starts_at' => now()->subDays(2)->toDateTimeString(),
            'ends_at' => now()->toDateTimeString(),
        ]);
        $this->assertInstanceOf(VehicleDowntimeDay::class, $down);
        $this->assertGreaterThan(0, (int) $down->lost_revenue_estimate);

        $template = $assets->upsertTemplate([
            'name' => 'فلتر زيت',
            'category' => 'زيوت',
            'default_life_km' => 10000,
            'default_life_days' => 180,
        ]);
        $this->assertInstanceOf(ConsumablePartTemplate::class, $template);
        $assets->setVehicleConsumable($this->vehicleA, $template, [
            'installed_on' => now()->toDateString(),
            'odometer_at_install' => 12000,
        ]);

        $this->makeContract([
            'number' => 'C-P1-USE',
            'vehicle_id' => $this->vehicleA->id,
            'status' => 'completed',
            'starts_at' => now()->subDays(40),
            'planned_ends_at' => now()->subDays(10),
            'actual_ends_at' => now()->subDays(10),
            'period_rent' => 30000,
            'approved_days' => 30,
        ]);

        $ranked = app(VehicleRecommendationService::class)->rankAvailable('اقتصادي');
        $this->assertNotEmpty($ranked);
        $weights = $ranked[0]['weights'];
        $this->assertSame(100, $weights['underuse'] + $weights['availability'] + $weights['manual']);

        $this->getJson('/api/vehicles/recommend?category='.urlencode('اقتصادي'))
            ->assertOk()
            ->assertJsonStructure(['data', 'weights']);

        $this->getJson("/api/vehicles/{$this->vehicleA->id}/dossier")
            ->assertOk()
            ->assertJsonStructure(['data' => ['tires', 'batteries', 'downtime', 'consumables', 'totals' => ['lost_revenue']]]);

        $this->postJson("/api/vehicles/{$this->vehicleA->id}/tires", [
            'position' => 'spare',
            'installed_on' => now()->toDateString(),
            'odometer_at_install' => 12000,
        ])->assertCreated();
    }

    public function test_hr_custody_permission_gate_on_assignments(): void
    {
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        $limited = User::factory()->create(['role_id' => $viewer->id, 'is_active' => true]);
        Sanctum::actingAs($limited);

        $this->postJson('/api/employee-assignments', [
            'employee_id' => $this->employee->id,
            'title' => 'ممنوع',
        ])->assertForbidden();

        $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 100,
            'advanced_on' => now()->toDateString(),
        ])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeContract(array $attrs): Contract
    {
        return Contract::query()->create([
            'number' => $attrs['number'] ?? ('C-P1-'.uniqid()),
            'customer_id' => $this->customer->id,
            'vehicle_id' => $attrs['vehicle_id'] ?? $this->vehicleA->id,
            'status' => $attrs['status'] ?? 'active',
            'starts_at' => $attrs['starts_at'],
            'planned_ends_at' => $attrs['planned_ends_at'],
            'actual_ends_at' => $attrs['actual_ends_at'] ?? null,
            'frozen_daily_rate' => $attrs['frozen_daily_rate'] ?? 1000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 2000,
            'period_rent' => $attrs['period_rent'] ?? 0,
            'final_total' => $attrs['final_total'] ?? null,
            'approved_days' => $attrs['approved_days'] ?? null,
            'discount' => 0,
            'other_fees' => 0,
        ]);
    }
}
