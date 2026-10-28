<?php

namespace Tests\Feature;

use App\Models\Customer;
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
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
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
            'fuel_level' => 80,
            'accessories' => [
                ['name' => 'مثلث تحذير', 'expected_qty' => 1, 'actual_qty' => 1, 'ok' => true],
            ],
        ])->assertOk();
        $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 10300,
            'damage_fee' => 100,
            'signature_data' => $signature,
            'fuel_level' => 40,
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

    public function test_cancel_before_handover_is_allowed(): void
    {
        $create = $this->postJson('/api/contracts', [
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
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-11-01T10:00:00',
            'planned_ends_at' => '2026-11-05T10:00:00',
        ])->assertCreated();

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => '2026-11-03T10:00:00',
            'planned_ends_at' => '2026-11-07T10:00:00',
        ])->assertStatus(422);
    }
}
