<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV9FixTicketsTest extends TestCase
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
            'operational' => [
                'min_customer_age' => 21,
                'require_handover_signature' => false,
                'block_expired_license' => false,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_v9_01_swap_maintenance_hides_the_contract_number_without_contracts_view(): void
    {
        [$customer, $original, $replacement] = $this->rentalPair();
        Sanctum::actingAs($this->admin());

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $original->id,
            'starts_at' => now()->subDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $number = (string) Contract::query()->findOrFail($id)->number;
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 1000])->assertOk();
        $this->postJson("/api/contracts/{$id}/swap", [
            'replacement_vehicle_id' => $replacement->id,
            'odometer_in' => 1100,
            'odometer_out' => 500,
            'fault_description' => 'عطل تبريد',
        ])->assertOk();

        $free = WorkOrder::query()->create([
            'vehicle_id' => $original->id,
            'workshop' => 'ملاحظة حرة CNT-00999',
            'opened_on' => now()->toDateString(),
            'odometer' => 1100,
            'status' => 'open',
            'puts_in_maintenance' => false,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $free->id,
            'description' => 'تغيير زيت CNT-00999',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 0,
        ]);

        $stored = (string) WorkOrder::query()->where('vehicle_id', $original->id)->where('workshop', 'like', 'تبديل بسبب عطل%')->value('workshop');
        $this->assertStringContainsString($number, $stored);

        foreach ([['vehicles.view'], ['vehicles.view', 'customers.view'], ['vehicles.view', 'maintenance.view']] as $keys) {
            Sanctum::actingAs($this->userWith($keys));
            $this->getJson('/api/contracts/'.$id)->assertStatus(403);
            $dash = $this->getJson('/api/dashboard')->assertOk()->json();
            $prep = $this->getJson('/api/fleet/daily-prep')->assertOk()->json('data');
            $dossier = $this->getJson('/api/vehicles/'.$original->id.'/dossier')->assertOk()->json('data');
            foreach ([$dash, $prep, $dossier] as $payload) {
                $this->assertStringNotContainsString($number, (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            $this->assertStringContainsString('تبديل بسبب عطل', (string) json_encode($dossier['work_orders'], JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('فحص عطل بعد التبديل', (string) json_encode($dossier['work_orders'], JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString('ملاحظة حرة CNT-00999', (string) json_encode($dossier['work_orders'], JSON_UNESCAPED_UNICODE));
            $this->assertNotEmpty($prep['days'][0]['groups']['maintenance']);
            $this->assertSame(0, $prep['totals']['pickups']);
            $this->assertSame(0, $prep['totals']['returns']);
        }

        $this->assertStringContainsString($number, (string) WorkOrder::query()->where('vehicle_id', $original->id)->where('workshop', 'like', 'تبديل بسبب عطل%')->value('workshop'));

        Sanctum::actingAs($this->userWith(['vehicles.view', 'maintenance.view']));
        $listed = $this->getJson('/api/work-orders')->assertOk()->json('data');
        $this->assertStringNotContainsString($number, (string) json_encode($listed, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('ملاحظة حرة CNT-00999', (string) json_encode($listed, JSON_UNESCAPED_UNICODE));

        Sanctum::actingAs($this->userWith(['vehicles.view', 'contracts.view', 'maintenance.view']));
        $visible = $this->getJson('/api/vehicles/'.$original->id.'/dossier')->assertOk()->json('data');
        $this->assertStringContainsString($number, (string) json_encode($visible['work_orders'], JSON_UNESCAPED_UNICODE));
        $prepVisible = $this->getJson('/api/fleet/daily-prep')->assertOk()->json('data');
        $this->assertStringContainsString($number, (string) json_encode($prepVisible, JSON_UNESCAPED_UNICODE));
    }

    public function test_v9_02_returning_today_is_null_without_contracts_view(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00', 'Africa/Cairo'));
        $viewer = $this->userWith(['vehicles.view']);
        $customers = $this->userWith(['vehicles.view', 'customers.view']);
        $contracts = $this->userWith(['vehicles.view', 'contracts.view']);
        $admin = $this->admin();

        Sanctum::actingAs($viewer);
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('kpis.returning_today', null)
            ->assertJsonPath('kpis.active_contracts', null);
        $this->getJson('/api/attention')->assertOk()->assertJsonPath('data.returning_today', null);
        $beforeFleet = $this->getJson('/api/dashboard')->json('fleet_status');
        $this->assertIsInt($beforeFleet['available']);

        Sanctum::actingAs($admin);
        $this->assertSame(0, $this->getJson('/api/dashboard')->json('kpis.returning_today'));

        [$customer, $vehicle] = $this->rentalPair();
        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-11-08 08:00:00',
            'planned_ends_at' => '2026-11-08 20:00:00',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 1000])->assertOk();

        $this->assertSame(1, $this->getJson('/api/dashboard')->json('kpis.returning_today'));
        Sanctum::actingAs($contracts);
        $this->assertSame(1, $this->getJson('/api/dashboard')->json('kpis.returning_today'));

        foreach ([$viewer, $customers] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/contracts/'.$id)->assertStatus(403);
            $dash = $this->getJson('/api/dashboard')->assertOk();
            $dash->assertJsonPath('kpis.returning_today', null);
            $dash->assertJsonPath('kpis.active_contracts', null);
            $this->assertSame(0, $dash->json('fleet_prep.totals.pickups'));
            $this->assertSame(0, $dash->json('fleet_prep.totals.returns'));
            $this->assertIsInt($dash->json('fleet_status.rented'));
            $this->getJson('/api/attention')->assertOk()->assertJsonPath('data.returning_today', null);
            $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk()->assertJsonPath('data.active_contract_ends_at', null);
        }

        Sanctum::actingAs($admin);
        $this->postJson("/api/contracts/{$id}/return", ['odometer_in' => 1100])->assertOk();
        $this->assertSame(0, $this->getJson('/api/dashboard')->json('kpis.returning_today'));
        Sanctum::actingAs($viewer);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('kpis.returning_today', null);
    }

    public function test_v9_03_vehicle_payloads_hide_owner_identity_and_monthly_amount(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك سري تسعة',
            'phone' => '01098980002',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V9-OWN',
            'plate' => 'م ل ك 9',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 400,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 3000,
            'owner_since' => '2026-04-01',
            'owner_agreement_starts_on' => '2026-04-01',
            'owner_agreement_ends_on' => '2026-12-14',
        ]);
        $customer = Customer::query()->create([
            'full_name' => 'عميل عقد مالك',
            'phone' => '01098980003',
            'birth_date' => '1991-01-01',
            'license_number' => 'LIC-V9-OWN',
            'license_expires_on' => '2028-01-01',
            'status' => 'active',
        ]);
        $contract = Contract::query()->create([
            'number' => 'CNT-V9-OWN',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(2),
        ]);

        $viewer = $this->userWith(['vehicles.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/owners/'.$owner->id)->assertStatus(403);
        $this->getJson('/api/vehicles/'.$vehicle->id.'/owner-agreement/print')->assertStatus(403);
        foreach ([
            $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk()->json('data'),
            $this->getJson('/api/vehicles/'.$vehicle->id.'/dossier')->assertOk()->json('data.vehicle'),
            collect($this->getJson('/api/vehicles?per_page=100')->assertOk()->json('data'))->firstWhere('id', $vehicle->id),
        ] as $row) {
            $this->assertOwnerHidden($row, $owner);
            $this->assertSame('2026-12-14', $row['owner_agreement_ends_on']);
            $this->assertSame('م ل ك 9', $row['plate']);
        }
        $dossier = $this->getJson('/api/vehicles/'.$vehicle->id.'/dossier')->assertOk()->json('data');
        $this->assertSame([], $dossier['owner_dues']);
        $this->assertSame([], $dossier['owner_payouts']);

        Sanctum::actingAs($this->userWith(['vehicles.view', 'contracts.view']));
        $nested = $this->getJson('/api/contracts/'.$contract->id)->assertOk()->json('data.vehicle');
        $this->assertOwnerHidden($nested, $owner);
        $this->assertSame('2026-12-14', substr((string) $nested['owner_agreement_ends_on'], 0, 10));

        Sanctum::actingAs($this->userWith(['vehicles.view', 'reports.view']));
        $finance = $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk()->json('data');
        $this->assertNull($finance['owner']);
        $this->assertNull($finance['owner_id']);
        $this->assertSame(3000, $finance['owner_monthly_amount']);
        $this->assertStringNotContainsString('مالك سري تسعة', (string) json_encode($finance, JSON_UNESCAPED_UNICODE));

        Sanctum::actingAs($this->userWith(['vehicles.view', 'owners.view']));
        $allowed = $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk()->json('data');
        $this->assertSame($owner->id, $allowed['owner_id']);
        $this->assertSame('مالك سري تسعة', $allowed['owner']['full_name']);
        $this->assertSame('01098980002', $allowed['owner']['phone']);
        $this->assertSame(3000, $allowed['owner_monthly_amount']);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/owners/'.$owner->id)->assertOk()
            ->assertJsonPath('data.full_name', 'مالك سري تسعة');

        Sanctum::actingAs($this->userWith(['vehicles.view', 'vehicles.update']));
        $this->putJson('/api/vehicles/'.$vehicle->id, [
            'owner_id' => null,
            'owner_monthly_amount' => null,
            'notes' => 'تعديل بلا مالك',
        ])->assertOk();
        $this->assertSame($owner->id, (int) $vehicle->fresh()->owner_id);
        $this->assertSame(3000, (int) $vehicle->fresh()->owner_monthly_amount);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function assertOwnerHidden(array $row, VehicleOwner $owner): void
    {
        $encoded = (string) json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->assertNull($row['owner_id']);
        $this->assertNull($row['owner']);
        $this->assertNull($row['owner_monthly_amount']);
        $this->assertStringNotContainsString($owner->full_name, $encoded);
        $this->assertStringNotContainsString($owner->phone, $encoded);
        $this->assertStringNotContainsString('3000', $encoded);
    }

    /**
     * @param  list<string>  $keys
     */
    private function userWith(array $keys): User
    {
        $role = Role::query()->create([
            'name' => 'v9_'.substr(md5(implode('|', $keys).microtime(true)), 0, 12),
            'label' => 'اختبار',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: Customer, 1: Vehicle, 2: Vehicle}
     */
    private function rentalPair(): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل تبديل',
            'phone' => '0107'.random_int(1000000, 9999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-V9-'.random_int(100, 999),
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $original = $this->vehicle('V9-A-'.random_int(100, 999), 1000);
        $replacement = $this->vehicle('V9-B-'.random_int(100, 999), 400);

        return [$customer, $original, $replacement];
    }

    private function vehicle(string $code, int $odometer = 1000): Vehicle
    {
        return Vehicle::query()->create([
            'code' => $code,
            'plate' => $code,
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => $odometer,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
        ]);
    }
}
