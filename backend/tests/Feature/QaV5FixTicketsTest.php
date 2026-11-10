<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\OwnerMonthDue;
use App\Models\PettyCashAccount;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV5FixTicketsTest extends TestCase
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
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $admin->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_v5_01_accrual_stops_when_the_owner_agreement_ends(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $owner = $this->owner();
        $this->vehicle($owner->id, [
            'owner_monthly_amount' => 2000,
            'owner_since' => '2026-06-01',
            'owner_agreement_starts_on' => '2026-06-01',
            'owner_agreement_ends_on' => '2026-08-31',
        ]);

        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk();

        $dues = OwnerMonthDue::query()->where('owner_id', $owner->id)->orderBy('year_month')->get();
        $this->assertSame(['2026-06', '2026-07', '2026-08'], $dues->pluck('year_month')->all());
        $this->assertSame(6000, (int) $dues->sum('amount'));
    }

    public function test_v5_02_full_calendar_month_is_exact_and_the_open_month_is_not_booked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 12:00:00', 'Africa/Cairo'));
        $owner = $this->owner();
        $vehicle = $this->vehicle($owner->id, [
            'owner_monthly_amount' => 3000,
            'owner_since' => '2026-09-15',
            'owner_agreement_starts_on' => '2026-09-15',
            'owner_agreement_ends_on' => '2026-12-14',
        ]);

        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk()
            ->assertJsonPath('message', 'تم ترحيل الاستحقاق.');
        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk()
            ->assertJsonPath('message', 'لا يوجد استحقاق جديد.');

        $byMonth = OwnerMonthDue::query()->where('vehicle_id', $vehicle->id)->pluck('amount', 'year_month');
        $this->assertSame(1600, (int) $byMonth['2026-09']);
        $this->assertSame(3000, (int) $byMonth['2026-10']);
        $this->assertArrayNotHasKey('2026-11', $byMonth->all());
        $this->assertSame(2, JournalEntry::query()->where('source', 'like', 'owner_accrual:'.$vehicle->id.':%')->count());
    }

    public function test_v5_03_early_handover_still_bills_one_day(): void
    {
        $customer = $this->customer('01055550001', 'LIC-V5-1');
        $vehicle = $this->vehicle(null, ['odometer' => 900, 'daily_rate' => 1000, 'default_deposit' => 2000]);
        $start = now()->addHours(2);
        $contract = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'planned_ends_at' => $start->copy()->addDays(3)->format('Y-m-d H:i:s'),
        ])->assertCreated();
        $this->assertIsInt($contract->json('data.customer_id'));
        $this->assertIsInt($contract->json('data.vehicle_id'));
        $id = $contract->json('data.id');

        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 1000])->assertOk();
        $returned = $this->postJson("/api/contracts/{$id}/return", ['odometer_in' => 1100])->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $returned->json('data.approved_days'));
        $this->assertGreaterThan(0, (int) $returned->json('data.period_rent'));
    }

    public function test_v5_04_deposit_must_match_the_agreed_amount(): void
    {
        $customer = $this->customer('01055550002', 'LIC-V5-2');
        $vehicle = $this->vehicle(null, ['default_deposit' => 2000]);
        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'planned_ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 100])->assertOk();

        $this->postJson("/api/contracts/{$id}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'V5-DEP',
            'amount' => 50,
        ])->assertStatus(422);
    }

    public function test_v5_09_rejects_duplicate_phone_and_underage_customer(): void
    {
        $this->customer('01055550009', 'LIC-V5-9');
        $this->postJson('/api/customers', $this->customerPayload('01055550009', 'LIC-V5-9B'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
        $payload = $this->customerPayload('01055550019', 'LIC-V5-9C');
        $payload['birth_date'] = now()->subYears(11)->toDateString();
        $this->postJson('/api/customers', $payload)->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    }

    public function test_v5_13_and_v5_19_and_v5_14_and_v5_17_and_v5_18(): void
    {
        $employee = Employee::query()->create([
            'name' => 'عهدة حد',
            'job_title' => 'فني',
            'hired_on' => '2024-01-01',
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 1000,
            'balance' => 600,
            'is_active' => true,
        ]);
        $this->postJson("/api/petty-cash/{$account->id}/move", ['type' => 'topup', 'amount' => 600])
            ->assertStatus(422);

        $this->postJson('/api/expenses', [
            'title' => 'مستقبلي',
            'amount' => 10,
            'spent_on' => '2027-05-01',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/accounting/payments', [
            'amount' => 5,
            'method' => 'bitcoin',
            'direction' => 'in',
            'link_type' => 'contract',
        ])->assertStatus(422);

        $this->getJson('/api/audit-logs?per_page=-1')->assertStatus(422);

        $vehicle = $this->vehicle(null, []);
        $this->putJson('/api/vehicles/'.$vehicle->id, [
            'tracker_sim_last_recharged_on' => '2026-11-08',
            'tracker_sim_next_recharge_on' => '2026-10-01',
        ])->assertStatus(422);

        $this->postJson('/api/vehicles/'.$vehicle->id.'/documents', [
            'type' => 'رخصة تسيير',
            'issued_on' => '2026-10-01',
            'expires_on' => '2026-09-01',
        ])->assertStatus(422);
    }

    public function test_v5_08_blocks_a_contract_that_outlasts_the_vehicle_license(): void
    {
        $customer = $this->customer('01055550008', 'LIC-V5-8');
        $vehicle = $this->vehicle(null, []);
        $this->postJson('/api/vehicles/'.$vehicle->id.'/documents', [
            'type' => 'رخصة تسيير',
            'issued_on' => '2025-01-01',
            'expires_on' => now()->addDays(2)->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            'planned_ends_at' => now()->addDays(20)->format('Y-m-d H:i:s'),
        ])->assertStatus(422);
    }

    public function test_v5_10_topup_and_stock_receipt_hit_the_asset_accounts(): void
    {
        $employee = Employee::query()->create([
            'name' => 'عهدة قيد',
            'job_title' => 'فني',
            'hired_on' => '2024-01-01',
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 5000,
            'balance' => 0,
            'is_active' => true,
        ]);
        $this->postJson("/api/petty-cash/{$account->id}/move", ['type' => 'topup', 'amount' => 1200])->assertOk();
        $this->assertTrue(JournalEntry::query()->where('source', 'like', 'petty_topup:%')->exists());

        $item = InventoryItem::query()->create([
            'code' => 'V5-OIL',
            'name' => 'زيت',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 1,
            'is_active' => true,
        ]);
        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $item->id,
            'received_on' => now()->toDateString(),
            'quantity' => 5,
            'unit_cost' => 100,
        ])->assertCreated();
        $this->assertTrue(JournalEntry::query()->where('source', 'like', 'inventory_receipt:%')->exists());
    }

    public function test_v5_12_deactivation_revokes_the_token(): void
    {
        $role = Role::query()->where('name', 'viewer')->firstOrFail();
        $staff = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]);
        $staff->createToken('qa');
        $this->putJson('/api/staff/'.$staff->id, ['is_active' => false])->assertOk();
        $this->assertSame(0, $staff->fresh()->tokens()->count());

        Sanctum::actingAs($staff->fresh());
        $this->getJson('/api/vehicles')->assertStatus(401);
    }

    private function owner(): VehicleOwner
    {
        return VehicleOwner::query()->create([
            'full_name' => 'مالك اختبار',
            'phone' => '010700'.random_int(10000, 99999),
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function vehicle(?int $ownerId, array $extra): Vehicle
    {
        return Vehicle::query()->create(array_merge([
            'code' => 'V5-'.random_int(1000, 9999),
            'plate' => 'V5 '.random_int(1000, 9999),
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 100,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
            'owner_id' => $ownerId,
        ], $extra));
    }

    private function customer(string $phone, string $license): Customer
    {
        $created = $this->postJson('/api/customers', $this->customerPayload($phone, $license))->assertCreated();

        return Customer::query()->findOrFail($created->json('data.id'));
    }

    /**
     * @return array<string, string>
     */
    private function customerPayload(string $phone, string $license): array
    {
        return [
            'full_name' => 'عميل '.$license,
            'phone' => $phone,
            'birth_date' => '1990-01-01',
            'license_number' => $license,
            'license_expires_on' => now()->addYears(3)->toDateString(),
        ];
    }
}
