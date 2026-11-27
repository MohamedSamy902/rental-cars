<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => true,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->user = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->user);
        app(AccountingService::class)->ensureChartOfAccounts();

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل اختبار',
            'phone' => '01001112233',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'T-001',
            'plate' => 'TEST 111',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
    }

    public function test_full_happy_path_approve_handover_return_and_reports(): void
    {
        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDays(3)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'R-1',
        ])->assertCreated();
        $signature = 'sig-'.str_repeat('client-ok-', 6);
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 10000,
            'signature_data' => $signature,
            'accessories' => [
                ['name' => 'مثلث تحذير', 'expected_qty' => 1, 'actual_qty' => 1, 'ok' => true],
            ],
        ])->assertOk();
        $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 10300,
            'damage_fee' => 100,
            'signature_data' => $signature,
            'damages' => [
                ['position' => 'الباب الأمامي', 'description' => 'خدش سطحي'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->getJson('/api/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.completed_contracts', 1);

        $this->getJson('/api/accounting/overview')
            ->assertOk()
            ->assertJsonStructure(['data' => ['months', 'accounts', 'payments_in']]);
    }

    public function test_prepaid_excess_km_covers_usage_and_bills_only_beyond(): void
    {
        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1000,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addDays(3)->toIso8601String(),
            'daily_rate' => 1000,
            'daily_km_allowance' => 200,
            'excess_km_rate' => 5,
            'prepaid_excess_km' => 1000,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertSame(1000, (int) $create->json('data.prepaid_excess_km'));
        $this->assertSame(5000, (int) $create->json('data.prepaid_excess_km_amount'));

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'R-PRE',
        ])->assertCreated();
        $signature = 'sig-'.str_repeat('prepaid-ok-', 5);
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 10000,
            'signature_data' => $signature,
        ])->assertOk();

        // 3 days × 200 = 600 allowed; driven 1800 → excess 1200; prepaid 1000 → billable 200 × 5 = 1000.
        $ret = $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 11800,
            'returned_at' => now()->startOfDay()->addDays(3)->toIso8601String(),
            'signature_data' => $signature,
        ])->assertOk();

        $this->assertSame(1200, (int) $ret->json('data.excess_km'));
        $this->assertSame(1000, (int) $ret->json('data.excess_km_amount'));
        $this->assertSame(5000, (int) $ret->json('data.prepaid_excess_km_amount'));
        // period_rent 3000 + prepaid 5000 + extra 1000 = 9000
        $this->assertSame(9000, (int) $ret->json('data.final_total'));

        $income4100 = Account::query()->where('code', '4100')->firstOrFail();
        $credit = (int) JournalLine::query()->where('account_id', $income4100->id)->sum('credit');
        $this->assertSame(6000, $credit);
    }

    public function test_unused_prepaid_excess_km_is_not_refunded(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'T-PRE-2',
            'plate' => 'TEST 222',
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1000,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addDays(3)->toIso8601String(),
            'daily_rate' => 1000,
            'daily_km_allowance' => 200,
            'excess_km_rate' => 5,
            'prepaid_excess_km' => 1000,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
        $id = (int) $create->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'R-UNDER',
        ])->assertCreated();
        $signature = 'sig-'.str_repeat('under-use-', 5);
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 5000,
            'signature_data' => $signature,
        ])->assertOk();

        // allowed 600; driven 900 → excess 300 < prepaid 1000 → no additional charge.
        $ret = $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 5900,
            'returned_at' => now()->startOfDay()->addDays(3)->toIso8601String(),
            'signature_data' => $signature,
        ])->assertOk();

        $this->assertSame(300, (int) $ret->json('data.excess_km'));
        $this->assertSame(0, (int) $ret->json('data.excess_km_amount'));
        $this->assertSame(5000, (int) $ret->json('data.prepaid_excess_km_amount'));
        $this->assertSame(8000, (int) $ret->json('data.final_total'));
    }

    public function test_cancel_before_handover_is_allowed(): void
    {
        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(4)->toIso8601String(),
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->postJson("/api/contracts/{$id}/cancel", ['reason' => 'طلب العميل'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_overlap_is_rejected(): void
    {
        $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-11-01T10:00:00',
            'planned_ends_at' => '2026-11-05T10:00:00',
        ])->assertCreated();

        $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-11-03T10:00:00',
            'planned_ends_at' => '2026-11-07T10:00:00',
        ])->assertStatus(422);
    }

    public function test_extend_active_contract_can_be_approved_and_handed_over(): void
    {
        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subDay()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
        $sourceId = (int) $create->json('data.id');

        $this->postJson("/api/contracts/{$sourceId}/approve")->assertOk();
        $this->postJson("/api/contracts/{$sourceId}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'R-EXT',
        ])->assertCreated();
        $signature = 'sig-'.str_repeat('extend-ok-', 5);
        $this->postJson("/api/contracts/{$sourceId}/handover", [
            'odometer_out' => 10000,
            'signature_data' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $extend = $this->postJson("/api/contracts/{$sourceId}/extend", [
            'planned_ends_at' => now()->addDays(10)->toIso8601String(),
        ])->assertCreated();
        $extId = (int) $extend->json('data.id');
        $this->assertSame($sourceId, (int) $extend->json('data.extended_from_contract_id'));

        $this->postJson("/api/contracts/{$extId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson("/api/contracts/{$extId}/handover", [
            'odometer_out' => 10250,
            'signature_data' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->getJson("/api/contracts/{$sourceId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_early_return_completes_and_keeps_pending_extension(): void
    {
        $create = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->subDays(2)->toIso8601String(),
            'planned_ends_at' => now()->addDays(5)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
        $sourceId = (int) $create->json('data.id');
        $this->postJson("/api/contracts/{$sourceId}/approve")->assertOk();
        $this->postJson("/api/contracts/{$sourceId}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'R-EARLY',
        ])->assertCreated();
        $signature = 'sig-'.str_repeat('early-ret-', 5);
        $this->postJson("/api/contracts/{$sourceId}/handover", [
            'odometer_out' => 10000,
            'signature_data' => $signature,
        ])->assertOk();

        $extId = (int) $this->postJson("/api/contracts/{$sourceId}/extend", [
            'planned_ends_at' => now()->addDays(12)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$sourceId}/return", [
            'odometer_in' => 10150,
            'signature_data' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->getJson("/api/contracts/{$extId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    }
}
