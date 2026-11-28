<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractsDueReceiveTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار استلام',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_due_receive_tab_lists_overdue_today_and_tomorrow_oldest_first(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل استلام',
            'phone' => '01001112233',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-RECV',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'RECV-1',
            'plate' => 'ر س 2001',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $overdue = Contract::query()->create([
            'number' => 'C-RECV-OVER',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'planned_ends_at' => now()->subDays(2),
            'approved_days' => 8,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        $today = Contract::query()->create([
            'number' => 'C-RECV-TODAY',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(3),
            'planned_ends_at' => now()->setTime(18, 0),
            'approved_days' => 3,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        $tomorrow = Contract::query()->create([
            'number' => 'C-RECV-TOM',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'approved_days' => 3,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        $later = Contract::query()->create([
            'number' => 'C-RECV-LATER',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->copy()->addDays(3),
            'approved_days' => 4,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);

        $res = $this->getJson('/api/contracts?tab=due_receive&per_page=50')->assertOk()->json();
        $ids = collect($res['data'])->pluck('id')->all();

        $this->assertContains($overdue->id, $ids);
        $this->assertContains($today->id, $ids);
        $this->assertContains($tomorrow->id, $ids);
        $this->assertNotContains($later->id, $ids);

        $this->assertSame(
            [$overdue->id, $today->id, $tomorrow->id],
            array_values(array_intersect($ids, [$overdue->id, $today->id, $tomorrow->id])),
            'الترتيب: متأخر ثم اليوم ثم بكرة',
        );

        $this->assertGreaterThanOrEqual(3, (int) ($res['summary']['due_receive'] ?? 0));
        $this->assertArrayHasKey('due_receive', $res['summary']);
    }
}
