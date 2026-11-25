<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0/P1 hardening: GET accounting never mutates journals; contracts list is slim; options view is tiny.
 */
class PerformanceReadPathHardeningTest extends TestCase
{
    use RefreshDatabase;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));

        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Africa/Cairo'));

        $customer = Customer::query()->create([
            'full_name' => 'عميل قراءة',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'LX-READ',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'READ-1',
            'plate' => 'RD 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2024,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1000,
            'default_deposit' => 0,
            'is_active' => true,
        ]);

        $this->contractId = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-11-16T12:00:00+02:00',
            'planned_ends_at' => '2026-12-16T12:00:00+02:00',
            'daily_rate' => 1000,
            'half_monthly_rate' => 15000,
            'monthly_rate' => 30000,
            'deposit' => 0,
            'odometer' => 1000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$this->contractId}/approve")->assertOk();
        $this->postJson("/api/contracts/{$this->contractId}/handover", [
            'odometer_out' => 1000,
            'signature_data' => 'sig-'.str_repeat('x', 40),
        ])->assertOk();
    }

    public function test_accounting_gets_do_not_mutate_journals(): void
    {
        $before = JournalEntry::query()->count();
        $beforeRecog = JournalEntry::query()->where('source', 'like', 'rent_recog%')->count();

        $this->getJson('/api/accounting/overview')->assertOk();
        $this->getJson('/api/reports/income-statement')->assertOk();
        $this->getJson('/api/accounting/months/2026/11')->assertOk();

        $this->assertSame($before, JournalEntry::query()->count(), 'GET must not create/delete journal entries');
        $this->assertSame(
            $beforeRecog,
            JournalEntry::query()->where('source', 'like', 'rent_recog%')->count(),
            'GET must not touch rent_recog'
        );
    }

    public function test_overview_still_shows_cross_month_rent_from_handover_write(): void
    {
        $months = collect($this->getJson('/api/accounting/overview')->assertOk()->json('data.months'));
        $nov = $months->first(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 11);
        $dec = $months->first(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 12);
        $this->assertNotNull($nov);
        $this->assertNotNull($dec);
        $this->assertGreaterThan(0, (int) $nov['rent_revenue']);
        $this->assertGreaterThan(0, (int) $dec['rent_revenue']);
    }

    public function test_contracts_list_payload_is_slim_and_options_smaller(): void
    {
        $list = $this->getJson('/api/contracts?per_page=50')->assertOk();
        $listBytes = strlen($list->getContent());
        $row = $list->json('data.0');
        $this->assertArrayHasKey('progress', $row);
        $this->assertArrayHasKey('ledger', $row);
        $this->assertArrayNotHasKey('accessories_json', $row);
        $this->assertArrayNotHasKey('notes', $row);

        $options = $this->getJson('/api/contracts?view=options&per_page=100&status=active')->assertOk();
        $optBytes = strlen($options->getContent());
        $optRow = $options->json('data.0');
        $this->assertArrayNotHasKey('progress', $optRow);
        $this->assertArrayNotHasKey('ledger', $optRow);
        $this->assertLessThan($listBytes, $optBytes);
    }

    public function test_sync_command_is_idempotent(): void
    {
        $before = JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();
        $this->artisan('accounting:sync-rent-recognition')->assertSuccessful();
        $after = JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();
        $this->assertSame($before, $after);
    }
}
