<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeImportantTasksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار مهام مهمة',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_dashboard_includes_permission_filtered_important_tasks(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]));

        $customer = Customer::query()->create([
            'full_name' => 'عميل مهام',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-TASK',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'TASK-1',
            'plate' => 'ط س ك 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 40000,
            'daily_rate' => 700,
            'default_deposit' => 3000,
            'is_active' => true,
            'tracker_sim_number' => '01099990000',
            'tracker_sim_next_recharge_on' => now()->addDays(3)->toDateString(),
        ]);

        Contract::query()->create([
            'number' => 'C-TASK-PEND',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(4),
            'frozen_daily_rate' => 700,
            'frozen_deposit' => 3000,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'status' => 'open',
            'opened_on' => now()->toDateString(),
            'odometer' => 40000,
            'puts_in_maintenance' => true,
            'workshop' => 'ورشة الاختبار',
            'notes' => 'صيانة عاجلة',
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => 'رخصة تسيير',
            'expires_on' => now()->addDays(10)->toDateString(),
            'file_path' => 'docs/license-front.jpg',
        ]);

        $res = $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'important_tasks' => [
                    ['id', 'rule', 'severity', 'title', 'subtitle', 'href', 'due_on'],
                ],
            ]);

        $rules = collect($res->json('important_tasks'))->pluck('rule')->all();
        $this->assertContains('contract_pending', $rules);
        $this->assertContains('work_order_open', $rules);
        $this->assertContains('license_expiring', $rules);
        $this->assertContains('sim_recharge', $rules);
    }

    public function test_important_tasks_hide_modules_without_permission(): void
    {
        $role = Role::query()->create([
            'name' => 'limited_home',
            'label' => 'محدود',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['contracts.view'])->pluck('id')
        );

        Sanctum::actingAs(User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
        ]));

        $customer = Customer::query()->create([
            'full_name' => 'عميل محدود',
            'phone' => '01033334444',
            'birth_date' => '1992-02-02',
            'license_number' => 'LIC-LIM',
            'license_expires_on' => now()->addYears(1)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'LIM-1',
            'plate' => 'ل م د 2',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 500,
            'default_deposit' => 2000,
            'is_active' => true,
            'tracker_sim_next_recharge_on' => now()->addDays(2)->toDateString(),
        ]);

        Contract::query()->create([
            'number' => 'C-LIM-PEND',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 500,
            'frozen_deposit' => 2000,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'status' => 'open',
            'opened_on' => now()->toDateString(),
            'odometer' => 20000,
            'puts_in_maintenance' => true,
            'workshop' => 'ورشة',
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => 'رخصة تسيير',
            'expires_on' => now()->addDays(5)->toDateString(),
            'file_path' => 'docs/lim-license.jpg',
        ]);

        $rules = collect($this->getJson('/api/dashboard')->assertOk()->json('important_tasks'))
            ->pluck('rule')
            ->all();

        $this->assertContains('contract_pending', $rules);
        $this->assertNotContains('work_order_open', $rules);
        $this->assertNotContains('license_expiring', $rules);
        $this->assertNotContains('sim_recharge', $rules);
    }
}
