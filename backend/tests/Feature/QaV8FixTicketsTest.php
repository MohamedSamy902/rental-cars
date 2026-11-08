<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\OwnerMonthDue;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV8FixTicketsTest extends TestCase
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
            'operational' => ['min_customer_age' => 21],
        ]);
        config(['app.public_url' => 'https://fleet.example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_v8_01_fleet_prep_follows_contract_and_customer_permissions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00:00', 'Africa/Cairo'));
        $fixture = $this->fleetFixture();

        $viewer = $this->userWith(['vehicles.view']);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/contracts/'.$fixture['pickup']->id)->assertStatus(403);
        $this->getJson('/api/customers/'.$fixture['customer']->id)->assertStatus(403);

        $dash = $this->getJson('/api/dashboard')->assertOk();
        $this->assertSecretsAbsent($dash->json(), $fixture);
        $dash->assertJsonPath('tasks', []);
        $dash->assertJsonPath('recent_contracts', []);
        $dash->assertJsonPath('kpis.customers', null);
        $dash->assertJsonPath('kpis.active_contracts', null);
        $dash->assertJsonPath('profitability_compare', null);
        $dash->assertJsonPath('daily_cash', null);
        $this->assertPrepHidden($dash->json('fleet_prep'), $fixture);
        $this->assertGreaterThan(0, $dash->json('fleet_prep.totals.maintenance'));

        $daily = $this->getJson('/api/fleet/daily-prep')->assertOk()->json('data');
        $this->assertPrepHidden($daily, $fixture);
        $this->assertGreaterThan(0, $daily['totals']['maintenance']);
        $this->assertStringContainsString($fixture['plate'], json_encode($daily, JSON_UNESCAPED_UNICODE));

        $contractsUser = $this->userWith(['vehicles.view', 'contracts.view', 'contracts.print']);
        Sanctum::actingAs($contractsUser);
        $this->getJson('/api/customers/'.$fixture['customer']->id)->assertStatus(403);
        $visible = $this->getJson('/api/fleet/daily-prep')->assertOk()->json('data');
        $pickup = $this->findPrep($visible, 'CNT-V8-PREP');
        $this->assertNotNull($pickup);
        $this->assertSame($fixture['pickup']->id, $pickup['contract_id']);
        $this->assertSame('approved', $pickup['status']);
        $this->assertNotEmpty($pickup['at']);
        $this->assertNull($pickup['customer']);
        $this->assertNull($pickup['customer_phone']);
        $this->assertNotNull($this->findPrep($visible, 'CNT-V8-RET'));
        $this->assertSecretsAbsent($visible, $fixture);
        $dashContracts = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSecretsAbsent($dashContracts, $fixture);
        $this->assertNotEmpty($dashContracts['tasks']);
        $this->assertNull($dashContracts['recent_contracts'][0]['customer']);

        $customersUser = $this->userWith(['vehicles.view', 'customers.view']);
        Sanctum::actingAs($customersUser);
        $this->getJson('/api/contracts/'.$fixture['pickup']->id)->assertStatus(403);
        $customerPrep = $this->getJson('/api/fleet/daily-prep')->assertOk()->json('data');
        $this->assertPrepHidden($customerPrep, $fixture);
        $this->assertSame(0, $customerPrep['totals']['pickups']);
        $this->assertSame(0, $customerPrep['totals']['returns']);

        Sanctum::actingAs($this->admin());
        $adminPrep = $this->getJson('/api/dashboard')->assertOk()->json('fleet_prep');
        $adminRow = $this->findPrep($adminPrep, 'CNT-V8-PREP');
        $this->assertSame('عميل تجهيز سري', $adminRow['customer']);
        $this->assertSame('01088880077', $adminRow['customer_phone']);
        $this->assertGreaterThan(0, $adminPrep['totals']['pickups']);
        $this->assertGreaterThan(0, $adminPrep['totals']['returns']);
    }

    public function test_v8_02_contract_serializers_hide_customer_profile_without_permission(): void
    {
        $fixture = $this->fleetFixture();
        $user = $this->userWith(['vehicles.view', 'contracts.view', 'contracts.print']);
        Sanctum::actingAs($user);

        $this->getJson('/api/customers/'.$fixture['customer']->id)->assertStatus(403);

        $detail = $this->getJson('/api/contracts/'.$fixture['pickup']->id)->assertOk();
        $detail->assertJsonPath('data.customer', null);
        $detail->assertJsonPath('data.number', 'CNT-V8-PREP');
        $detail->assertJsonPath('data.final_total', 1000);
        $detail->assertJsonPath('data.period_rent', 1000);
        $this->assertSecretsAbsent($detail->json(), $fixture);

        $list = $this->getJson('/api/contracts?per_page=100')->assertOk()->json('data');
        $row = collect($list)->firstWhere('number', 'CNT-V8-PREP');
        $this->assertNotNull($row);
        $this->assertNull($row['customer']);
        $this->assertSame(1000, (int) $row['final_total']);
        $this->assertSecretsAbsent($list, $fixture);

        $print = $this->getJson('/api/contracts/'.$fixture['pickup']->id.'/print')->assertOk();
        $print->assertJsonPath('data.contract.customer', null);
        $this->assertSecretsAbsent($print->json(), $fixture);

        $dossier = $this->getJson('/api/vehicles/'.$fixture['vehicle']->id.'/dossier')->assertOk()->json('data');
        $this->assertNotEmpty($dossier['contracts']);
        $this->assertNull($dossier['contracts'][0]['customer']);
        $this->assertSame(0, $dossier['totals']['revenue_amount']);
        $this->assertSame(0, collect($dossier['charts']['revenue_by_month'])->sum('amount'));

        Sanctum::actingAs($this->userWith(['vehicles.view']));
        $this->getJson('/api/contracts/'.$fixture['pickup']->id)->assertStatus(403);
        $this->getJson('/api/contracts')->assertStatus(403);

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/contracts/'.$fixture['pickup']->id)
            ->assertOk()
            ->assertJsonPath('data.customer.full_name', 'عميل تجهيز سري')
            ->assertJsonPath('data.customer.phone', '01088880077')
            ->assertJsonPath('data.customer.birth_date', '1990-06-15')
            ->assertJsonPath('data.customer.license_number', 'QA-V8-LIC-SECRET')
            ->assertJsonPath('data.customer.credit_balance', 4321)
            ->assertJsonPath('data.final_total', 1000);
    }

    public function test_v8_03_owner_accrual_uses_inclusive_civil_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $aprilFull = $this->ownerVehicle('2026-04-01', '2026-04-30', 3000);
        $aprilSlice = $this->ownerVehicle('2026-04-23', '2026-04-25', 3000);
        $september = $this->ownerVehicle('2026-09-15', '2026-12-14', 3000);

        foreach ([$aprilFull, $aprilSlice, $september] as $row) {
            $this->getJson('/api/owners/'.$row['owner']->id)->assertOk();
            $this->getJson('/api/owners/'.$row['owner']->id.'/balance')->assertOk();
        }
        $this->assertSame(0, OwnerMonthDue::query()->count());

        $this->postJson('/api/owners/'.$aprilFull['owner']->id.'/accruals', [])->assertOk()
            ->assertJsonPath('message', 'تم ترحيل الاستحقاق.');
        $this->postJson('/api/owners/'.$aprilSlice['owner']->id.'/accruals', [])->assertOk();
        $this->postJson('/api/owners/'.$september['owner']->id.'/accruals', [])->assertOk();

        $this->assertSame(3000, $this->dueAmount($aprilFull['vehicle']->id, '2026-04'));
        $this->assertSame(300, $this->dueAmount($aprilSlice['vehicle']->id, '2026-04'));
        $this->assertSame(1600, $this->dueAmount($september['vehicle']->id, '2026-09'));
        $this->assertSame(3000, $this->dueAmount($september['vehicle']->id, '2026-10'));
        $this->assertNull(OwnerMonthDue::query()->where('vehicle_id', $september['vehicle']->id)->where('year_month', '2026-11')->first());

        $before = OwnerMonthDue::query()->count();
        $this->postJson('/api/owners/'.$september['owner']->id.'/accruals', [])->assertOk()
            ->assertJsonPath('message', 'لا يوجد استحقاق جديد.');
        $this->assertSame($before, OwnerMonthDue::query()->count());

        OwnerMonthDue::query()
            ->where('vehicle_id', $aprilFull['vehicle']->id)
            ->where('year_month', '2026-04')
            ->update(['amount' => 111]);
        $this->postJson('/api/owners/'.$aprilFull['owner']->id.'/accruals', [])->assertOk()
            ->assertJsonPath('message', 'لا يوجد استحقاق جديد.');
        $this->assertSame(111, $this->dueAmount($aprilFull['vehicle']->id, '2026-04'));
    }

    public function test_v8_04_vehicle_qr_uses_configured_public_origin(): void
    {
        $fixture = $this->fleetFixture();
        $expected = 'https://fleet.example.test/showroom?vehicle='.$fixture['vehicle']->id;

        Sanctum::actingAs($this->userWith(['vehicles.view']));
        $this->getJson('/api/qr?type=customer&id='.$fixture['customer']->id)->assertStatus(403);

        Sanctum::actingAs($this->admin());
        $qr = $this->withHeaders([
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Proto' => 'http',
        ])->getJson('/api/qr?type=vehicle&id='.$fixture['vehicle']->id)->assertOk();
        $qr->assertJsonPath('data.qr', $expected);
        $dossier = $this->withHeaders([
            'X-Forwarded-Host' => 'evil.example',
        ])->getJson('/api/vehicles/'.$fixture['vehicle']->id.'/dossier')->assertOk();
        $dossier->assertJsonPath('data.qr.payload', $expected);

        $encoded = json_encode([$qr->json(), $dossier->json('data.qr')], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('127.0.0.1', $encoded);
        $this->assertStringNotContainsString('localhost', $encoded);
        $this->assertStringNotContainsString('evil.example', $encoded);
        $this->assertStringNotContainsString($fixture['customer']->phone, $encoded);

        $this->getJson('/api/qr?type=customer&id='.$fixture['customer']->id)
            ->assertOk()
            ->assertJsonPath('data.qr', 'https://fleet.example.test/department-notice?customer_id='.$fixture['customer']->id);
    }

    /**
     * @param  array<string, mixed>  $prep
     * @param  array<string, mixed>  $fixture
     */
    private function assertPrepHidden(array $prep, array $fixture): void
    {
        $this->assertSame(0, $prep['totals']['pickups']);
        $this->assertSame(0, $prep['totals']['returns']);
        $this->assertSame([], $this->contractRows($prep));
        $encoded = json_encode($prep, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('contract_id', $encoded);
        $this->assertSecretsAbsent($prep, $fixture);
        foreach (['CNT-V8-PREP', 'CNT-V8-PEND', 'CNT-V8-RET'] as $number) {
            $this->assertStringNotContainsString($number, $encoded);
        }
    }

    /**
     * @param  array<string, mixed>  $prep
     * @return list<array<string, mixed>>
     */
    private function contractRows(array $prep): array
    {
        $rows = [];
        foreach ($prep['days'] as $day) {
            foreach (['pickups', 'returns'] as $group) {
                foreach ($day['groups'][$group] as $row) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $prep
     * @return array<string, mixed>|null
     */
    private function findPrep(array $prep, string $number): ?array
    {
        foreach ($this->contractRows($prep) as $row) {
            if (($row['number'] ?? null) === $number) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function assertSecretsAbsent(mixed $payload, array $fixture): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        foreach ([
            'عميل تجهيز سري',
            '01088880077',
            'QA-V8-LIC-SECRET',
            '1990-06-15',
            'ضامن سري',
            '01088880078',
            'QA-V8-GUAR-SECRET',
            'مالك سري تجهيز',
            '4321',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $encoded);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function userWith(array $keys): User
    {
        $role = Role::query()->create([
            'name' => 'v8_'.substr(md5(implode('|', $keys).microtime(true)), 0, 12),
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
     * @return array{owner: VehicleOwner, customer: Customer, guarantor: Customer, vehicle: Vehicle, pickup: Contract, plate: string}
     */
    private function fleetFixture(): array
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك سري تجهيز',
            'phone' => '01088880079',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'full_name' => 'عميل تجهيز سري',
            'phone' => '01088880077',
            'birth_date' => '1990-06-15',
            'license_number' => 'QA-V8-LIC-SECRET',
            'license_expires_on' => '2028-06-15',
            'credit_balance' => 4321,
            'status' => 'active',
        ]);
        $guarantor = Customer::query()->create([
            'full_name' => 'ضامن سري',
            'phone' => '01088880078',
            'birth_date' => '1988-02-02',
            'license_number' => 'QA-V8-GUAR-SECRET',
            'license_expires_on' => '2028-02-02',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V8-PREP',
            'plate' => 'ص ي ا 8',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 3000,
            'owner_agreement_starts_on' => '2026-11-01',
            'owner_agreement_ends_on' => '2026-11-18',
        ]);
        $pickup = Contract::query()->create([
            'number' => 'CNT-V8-PREP',
            'customer_id' => $customer->id,
            'guarantor_customer_id' => $guarantor->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => '2026-11-09 17:00:00',
            'planned_ends_at' => '2026-11-10 17:00:00',
            'final_total' => 1000,
            'period_rent' => 1000,
        ]);
        Contract::query()->create([
            'number' => 'CNT-V8-RET',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => '2026-11-07 10:00:00',
            'planned_ends_at' => '2026-11-09 12:00:00',
            'final_total' => 1000,
            'period_rent' => 1000,
        ]);
        Contract::query()->create([
            'number' => 'CNT-V8-PEND',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => '2026-11-12 09:00:00',
            'planned_ends_at' => '2026-11-13 09:00:00',
            'final_total' => 1000,
            'period_rent' => 1000,
        ]);
        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'status' => 'open',
            'opened_on' => '2026-11-08',
            'odometer' => 1000,
            'puts_in_maintenance' => false,
            'workshop' => 'ورشة التجهيز',
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        return [
            'owner' => $owner,
            'customer' => $customer,
            'guarantor' => $guarantor,
            'vehicle' => $vehicle,
            'pickup' => $pickup,
            'plate' => 'ص ي ا 8',
        ];
    }

    /**
     * @return array{owner: VehicleOwner, vehicle: Vehicle}
     */
    private function ownerVehicle(string $starts, string $ends, int $monthly): array
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك استحقاق '.$starts,
            'phone' => '0107'.random_int(1000000, 9999999),
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V8-'.random_int(1000, 9999),
            'plate' => 'V8 '.random_int(1000, 9999),
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 100,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => $monthly,
            'owner_since' => $starts,
            'owner_agreement_starts_on' => $starts,
            'owner_agreement_ends_on' => $ends,
        ]);

        return ['owner' => $owner, 'vehicle' => $vehicle];
    }

    private function dueAmount(int $vehicleId, string $yearMonth): int
    {
        return (int) OwnerMonthDue::query()
            ->where('vehicle_id', $vehicleId)
            ->where('year_month', $yearMonth)
            ->value('amount');
    }
}
