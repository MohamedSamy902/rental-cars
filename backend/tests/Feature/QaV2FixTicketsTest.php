<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Evidence-backed fixes for QA host v2 tickets B01–B13 (API scope).
 */
class QaV2FixTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['evaluations' => true, 'inventory' => true],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_b01_month_lock_allocates_planned_rent_across_months(): void
    {
        [$customer, $vehicle] = $this->party('B01');

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-01-30T00:00:00+02:00',
            'planned_ends_at' => '2026-02-03T00:00:00+02:00',
            'daily_rate' => 100,
            'deposit' => 0,
            'odometer' => 1000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 1000,
            'signature_data' => 'sig-'.str_repeat('x', 40),
        ])->assertOk();

        $this->postJson('/api/accounting/lock-month', ['year' => 2026, 'month' => 1])->assertOk();
        $jan = $this->getJson('/api/accounting/months/2026/1')->assertOk()->json('data');
        $this->assertTrue($jan['lock']['locked']);
        $this->assertSame(200, $jan['summary']['rent_revenue']);
        $this->assertNotEmpty($jan['rents']);

        $this->postJson('/api/accounting/lock-month', ['year' => 2026, 'month' => 2])->assertOk();
        $feb = $this->getJson('/api/accounting/months/2026/2')->assertOk()->json('data');
        $this->assertSame(200, $feb['summary']['rent_revenue']);

        $this->postJson('/api/accounting/lock-month', ['year' => 2026, 'month' => 1])->assertStatus(422);
        $rentRecogBefore = JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();
        $this->assertGreaterThanOrEqual(2, $rentRecogBefore);

        $this->postJson('/api/accounting/reopen-month', [
            'year' => 2026, 'month' => 1, 'reason' => 'اختبار إعادة فتح',
        ])->assertOk();
        $this->postJson('/api/accounting/lock-month', ['year' => 2026, 'month' => 1])->assertOk();
        $this->assertSame(200, $this->getJson('/api/accounting/months/2026/1')->json('data.summary.rent_revenue'));
        $this->assertSame(
            $rentRecogBefore,
            JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count(),
            'reopen/relock must not duplicate rent_recog journals',
        );
    }

    public function test_b02_rejects_contract_overpayment(): void
    {
        [$customer, $vehicle] = $this->party('B02');
        $id = $this->approvedContract($customer, $vehicle, 100, '2026-03-01T00:00:00+02:00', '2026-03-05T00:00:00+02:00');

        $this->postJson('/api/accounting/payments', [
            'amount' => 100,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'P100',
        ])->assertCreated();

        $this->postJson('/api/accounting/payments', [
            'amount' => 301,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'POVER',
        ])->assertStatus(422);

        $this->assertSame(2, Payment::query()->where('link_type', 'contract')->where('link_id', $id)->count());
        $this->assertSame(101, (int) $this->getJson("/api/contracts/{$id}")->json('data.ledger.paid'));
        $this->assertSame(299, (int) $this->getJson("/api/contracts/{$id}")->json('data.ledger.balance'));

        $this->postJson('/api/accounting/payments', [
            'amount' => 299,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'P300',
        ])->assertCreated();
        $this->assertSame(0, (int) $this->getJson("/api/contracts/{$id}")->json('data.ledger.balance'));
    }

    public function test_b03_customer_date_only_round_trip(): void
    {
        [$customer, $vehicle] = $this->party('B03');

        $this->putJson("/api/customers/{$customer->id}", [
            'full_name' => $customer->full_name,
            'phone' => $customer->phone,
            'birth_date' => '1990-01-01',
            'license_number' => 'QA-LIC',
            'license_expires_on' => '2029-01-01',
        ])->assertOk()
            ->assertJsonPath('data.birth_date', '1990-01-01')
            ->assertJsonPath('data.license_expires_on', '2029-01-01');

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.birth_date', '1990-01-01')
            ->assertJsonPath('data.license_expires_on', '2029-01-01');

        $listRow = collect($this->getJson('/api/customers')->assertOk()->json('data'))
            ->firstWhere('id', $customer->id);
        $this->assertNotNull($listRow);
        $this->assertSame('1990-01-01', $listRow['birth_date']);
        $this->assertSame('2029-01-01', $listRow['license_expires_on']);

        $contractId = $this->approvedContract(
            $customer,
            $vehicle,
            50,
            '2026-03-10T00:00:00+02:00',
            '2026-03-12T00:00:00+02:00',
        );
        $embedded = $this->getJson("/api/contracts/{$contractId}")->assertOk()->json('data.customer');
        $this->assertSame('1990-01-01', $embedded['birth_date']);
        $this->assertSame('2029-01-01', $embedded['license_expires_on']);
        $this->assertDoesNotMatchRegularExpression('/T|Z/', (string) $embedded['birth_date']);
    }

    public function test_b04_cash_receipt_posts_journal(): void
    {
        [$customer, $vehicle] = $this->party('B04');
        $id = $this->approvedContract($customer, $vehicle, 100, '2026-04-01T00:00:00+02:00', '2026-04-05T00:00:00+02:00');

        $before = collect($this->getJson('/api/reports/trial-balance')->json('data'))
            ->firstWhere('code', '1000');

        $this->postJson('/api/accounting/payments', [
            'amount' => 100,
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'CASH100',
        ])->assertCreated();

        $after = collect($this->getJson('/api/reports/trial-balance')->json('data'))
            ->firstWhere('code', '1000');
        $this->assertSame((int) $before['debit'] + 100, (int) $after['debit']);

        $ar = collect($this->getJson('/api/reports/trial-balance')->json('data'))
            ->firstWhere('code', '1200');
        $this->assertGreaterThanOrEqual(100, (int) $ar['credit']);
        $this->assertTrue(JournalEntry::query()->where('source', 'like', 'payment:%')->exists());
    }

    public function test_b05_arabic_payment_method_appears_in_report(): void
    {
        [$customer, $vehicle] = $this->party('B05');
        $id = $this->approvedContract($customer, $vehicle, 50, '2026-05-01T00:00:00+02:00', '2026-05-05T00:00:00+02:00');

        $this->postJson('/api/accounting/payments', [
            'amount' => 25,
            'method' => 'نقدي',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'AR-CASH',
        ])->assertCreated();

        $this->postJson('/api/accounting/payments', [
            'amount' => 10,
            'method' => 'محفظة',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
            'reference' => 'AR-WALLET',
        ])->assertCreated();

        $methods = $this->getJson('/api/reports/payment-methods')->assertOk()->json('data');
        $cash = collect($methods)->firstWhere('method', 'cash');
        $this->assertNotNull($cash);
        $this->assertSame(26, (int) $cash['in']);

        $wallet = collect($methods)->first(
            fn ($row) => in_array($row['method'] ?? '', ['wallet', 'محفظة'], true)
                || str_contains((string) ($row['label'] ?? ''), 'محفظة')
                || str_contains((string) ($row['method'] ?? ''), 'wallet'),
        );
        $this->assertNotNull($wallet, 'Arabic محفظة must normalize into payment-methods report');
        $this->assertSame(10, (int) $wallet['in']);
    }

    public function test_b06_fleet_summary_matches_dashboard_buckets(): void
    {
        $summary = $this->getJson('/api/reports/summary')->assertOk()->json('data.fleet');
        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $status = $dashboard['fleet_status'];

        $this->assertSame((int) $dashboard['fleet_total'], (int) $summary['total']);
        $this->assertSame((int) $status['available'], (int) $summary['available']);
        $this->assertSame((int) $status['reserved'], (int) $summary['reserved']);
        $this->assertSame((int) $status['rented'], (int) $summary['rented']);
        $this->assertSame((int) $status['maintenance'], (int) $summary['maintenance']);
        $this->assertSame((int) $status['out_of_service'], (int) $summary['out_of_service']);
        $bucketSum = $summary['available'] + $summary['reserved'] + $summary['rented']
            + $summary['maintenance'] + $summary['out_of_service'];
        $this->assertSame($summary['total'], $bucketSum);
    }

    public function test_b07_anonymous_non_json_accept_returns_401(): void
    {
        // Fresh unauthenticated client (setUp Sanctum actor must not leak).
        $this->app['auth']->forgetGuards();
        $this->withoutToken();

        $this->call('GET', '/api/dashboard', server: [
            'HTTP_ACCEPT' => '*/*',
        ])->assertStatus(401)->assertJsonStructure(['message']);

        $this->call('GET', '/api/customers', server: [
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(401);
    }

    public function test_b08_evaluations_endpoints_are_retired(): void
    {
        $this->getJson('/api/evaluations')->assertNotFound();
        $this->postJson('/api/evaluations', [
            'employee_id' => 1,
            'score' => 5,
            'period' => 'QA',
        ])->assertNotFound();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('evaluations'));
    }

    public function test_b09_inventory_batch_rejects_zero_unit_cost(): void
    {
        $itemId = $this->postJson('/api/inventory/items', [
            'name' => 'QA-PRICE',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 1,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => '2026-11-02',
            'quantity' => 2,
            'unit_cost' => 0,
            'supplier' => 'QA',
        ])->assertStatus(422);

        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => '2026-11-02',
            'quantity' => 2,
            'unit_cost' => 15,
            'supplier' => 'QA',
        ])->assertCreated();
    }

    public function test_b10_fifo_preserves_exact_consumed_value(): void
    {
        [, $vehicle] = $this->party('B10');

        $itemId = $this->postJson('/api/inventory/items', [
            'name' => 'QA-FIFO',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 1,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => '2026-11-01',
            'quantity' => 2,
            'unit_cost' => 10,
            'supplier' => 'QA',
        ])->assertCreated();
        $this->postJson('/api/inventory/batches', [
            'inventory_item_id' => $itemId,
            'received_on' => '2026-11-02',
            'quantity' => 3,
            'unit_cost' => 20,
            'supplier' => 'QA',
        ])->assertCreated();

        $wo = $this->postJson('/api/work-orders', [
            'vehicle_id' => $vehicle->id,
            'odometer' => 1000,
            'next_service_on' => now()->addMonths(3)->toDateString(),
            'workshop' => 'QA',
            'opened_on' => '2026-11-08',
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'lines' => [[
                'description' => '3 units',
                'part_source' => 'stock',
                'inventory_item_id' => $itemId,
                'quantity' => 3,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame(40, (int) $wo['parts_cost']);

        $invCredit = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '1400'))
            ->sum('credit');
        $this->assertSame(40, $invCredit);
    }

    public function test_b11_profitability_fleet_equals_vehicle_sum(): void
    {
        $data = $this->getJson('/api/reports/profitability-compare')->assertOk()->json('data');
        $fleet = (int) $data['fleet']['current']['revenue'];
        $sum = collect($data['vehicles'])->sum(fn ($v) => (int) $v['current']['revenue']);
        $this->assertSame($fleet, $sum);
    }

    public function test_b12_customer_detail_survives_violation_history(): void
    {
        [$customer, $vehicle] = $this->party('B12');
        $id = $this->approvedContract($customer, $vehicle, 100, '2026-06-01T00:00:00+02:00', '2026-06-03T00:00:00+02:00');

        Violation::query()->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'contract_id' => $id,
            'amount' => 50,
            'occurred_at' => now(),
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $this->getJson("/api/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonStructure(['data' => ['contracts', 'violations']]);
    }

    public function test_b13_statement_shows_live_contract_charges(): void
    {
        [$customer, $vehicle] = $this->party('B13');
        $id = $this->approvedContract($customer, $vehicle, 100, '2026-07-01T00:00:00+02:00', '2026-07-03T00:00:00+02:00');

        $this->postJson('/api/accounting/payments', [
            'amount' => 25,
            'method' => 'نقدي',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $id,
        ])->assertCreated();

        $statement = $this->getJson("/api/reports/customers/{$customer->id}/statement")->assertOk()->json('data');
        $row = collect($statement['contracts'])->firstWhere('id', $id);
        $this->assertNotNull($row);
        $this->assertSame(200, (int) $row['total']);
        $this->assertSame(26, (int) $row['paid']);
        $this->assertSame(174, (int) $row['remaining']);
    }

    /** @return array{0: Customer, 1: Vehicle} */
    private function party(string $tag): array
    {
        $customer = Customer::query()->create([
            'full_name' => "عميل {$tag}",
            'phone' => '010'.random_int(10000000, 99999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'L-'.$tag,
            'license_expires_on' => '2030-01-01',
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-'.$tag.'-'.random_int(100, 999),
            'plate' => 'QA-'.$tag.'-'.random_int(1000, 9999),
            'brand' => 'تويوتا',
            'model' => 'QA',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 100,
            'default_deposit' => 0,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }

    private function approvedContract(
        Customer $customer,
        Vehicle $vehicle,
        int $rate,
        string $start,
        string $end,
    ): int {
        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => $start,
            'planned_ends_at' => $end,
            'daily_rate' => $rate,
            'deposit' => 0,
            'odometer' => 1000,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();

        return (int) $id;
    }
}
