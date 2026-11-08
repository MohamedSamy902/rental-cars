<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV7DossierPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_vehicles_only_dossier_hides_contracts_customers_and_revenue(): void
    {
        [$vehicle, $customer, $contract] = $this->rental();
        $viewer = $this->userWith(['vehicles.view']);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/contracts/'.$contract->id)->assertStatus(403);
        $this->getJson('/api/customers/'.$customer->id)->assertStatus(403);
        $this->getJson('/api/qr?type=customer&id='.$customer->id)->assertStatus(403);

        $dossier = $this->getJson('/api/vehicles/'.$vehicle->id.'/dossier')->assertOk()->json('data');
        $this->assertSame([], $dossier['contracts']);
        $this->assertSame(0, $dossier['totals']['contracts']);
        $this->assertSame(0, $dossier['totals']['revenue_amount']);
        $this->assertSame([], $dossier['charts']['contracts_by_status']);
        $encoded = json_encode($dossier, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($customer->full_name, $encoded);
        $this->assertStringNotContainsString($customer->phone, $encoded);
        $this->assertSame(0, collect($dossier['charts']['revenue_by_month'])->sum('amount'));

        $estimate = $this->getJson('/api/vehicles/'.$vehicle->id.'/estimated-odometer')->assertOk()->json('data');
        $this->assertArrayNotHasKey('contract_id', $estimate);
    }

    public function test_contract_view_without_finance_keeps_rows_and_hides_revenue_aggregates(): void
    {
        [$vehicle, $customer] = $this->rental();
        $user = $this->userWith(['vehicles.view', 'contracts.view']);
        Sanctum::actingAs($user);

        $dossier = $this->getJson('/api/vehicles/'.$vehicle->id.'/dossier')->assertOk()->json('data');
        $this->assertCount(1, $dossier['contracts']);
        $this->assertSame('CNT-V7-1', $dossier['contracts'][0]['number']);
        $this->assertNull($dossier['contracts'][0]['customer']);
        $this->assertSame(0, $dossier['totals']['revenue_amount']);
        $this->assertSame(0, collect($dossier['charts']['revenue_by_month'])->sum('amount'));
        $this->assertStringNotContainsString($customer->phone, json_encode($dossier, JSON_UNESCAPED_UNICODE));
    }

    public function test_reporting_role_still_sees_contract_and_revenue(): void
    {
        [$vehicle] = $this->rental();
        $user = $this->userWith(['vehicles.view', 'contracts.view', 'customers.view', 'reports.view']);
        Sanctum::actingAs($user);

        $dossier = $this->getJson('/api/vehicles/'.$vehicle->id.'/dossier')->assertOk()->json('data');
        $this->assertSame('CNT-V7-1', $dossier['contracts'][0]['number']);
        $this->assertSame('عميل الملف', $dossier['contracts'][0]['customer']['full_name']);
        $this->assertSame(1000, $dossier['totals']['revenue_amount']);
        $this->assertSame(1000, collect($dossier['charts']['revenue_by_month'])->sum('amount'));
    }

    /**
     * @param  list<string>  $keys
     */
    private function userWith(array $keys): User
    {
        $role = Role::query()->create([
            'name' => 'v7_'.implode('_', $keys),
            'label' => 'اختبار',
        ]);
        $ids = Permission::query()->whereIn('key', $keys)->pluck('id');
        $role->permissions()->sync($ids);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /**
     * @return array{0: Vehicle, 1: Customer, 2: Contract}
     */
    private function rental(): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل الملف',
            'phone' => '01077771001',
            'license_number' => 'LIC-V7',
            'license_expires_on' => now()->addYear()->toDateString(),
            'birth_date' => '1990-01-01',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V7-66',
            'plate' => 'V7 66',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'CNT-V7-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'completed',
            'starts_at' => now()->subDays(3),
            'planned_ends_at' => now()->subDay(),
            'actual_ends_at' => now()->subDay(),
            'final_total' => 1000,
            'period_rent' => 1000,
        ]);

        return [$vehicle, $customer, $contract];
    }
}
