<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
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

class ContractDeliveryServiceTest extends TestCase
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
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->user = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->user);
        app(AccountingService::class)->ensureChartOfAccounts();

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل تسليم',
            'phone' => '01009998877',
            'birth_date' => '1991-02-02',
            'license_number' => 'LIC-DEL',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'DEL-1',
            'plate' => 'DEL 100',
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 1000,
            'default_deposit' => 2500,
            'is_active' => true,
        ]);
    }

    public function test_create_with_delivery_fee_increases_charge_total_and_payment_cap(): void
    {
        $create = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addDay()->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2500,
            'has_delivery_service' => true,
            'delivery_service_fee' => 1000,
            'delivery_service_notes' => 'تسليم مطار القاهرة',
            'payment_amount' => 1500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(2)->toDateString(),
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertTrue((bool) $create->json('data.has_delivery_service'));
        $this->assertSame(1000, (int) $create->json('data.delivery_service_fee'));
        $this->assertSame('تسليم مطار القاهرة', $create->json('data.delivery_service_notes'));

        $show = $this->getJson("/api/contracts/{$id}")->assertOk();
        $this->assertSame(2000, (int) $show->json('data.ledger.contract_total'));
        $this->assertSame(1500, (int) $show->json('data.ledger.paid'));
        $this->assertSame(500, (int) $show->json('data.ledger.balance'));

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDays(10)->toIso8601String(),
            'planned_ends_at' => now()->addDays(11)->toIso8601String(),
            'daily_rate' => 1000,
            'has_delivery_service' => true,
            'delivery_service_fee' => 1000,
            'payment_amount' => 2500,
            'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_amount']);
    }

    public function test_delivery_fee_required_when_enabled_and_cleared_when_disabled(): void
    {
        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDay()->toIso8601String(),
            'daily_rate' => 1000,
            'has_delivery_service' => true,
            'payment_amount' => 500,
            'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors(['delivery_service_fee']);

        $id = (int) $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDay()->toIso8601String(),
            'daily_rate' => 1000,
            'has_delivery_service' => true,
            'delivery_service_fee' => 800,
            'delivery_service_notes' => 'الشيخ زايد',
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(3)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/pricing", [
            'daily_rate' => 1000,
            'daily_km_allowance' => 200,
            'excess_km_rate' => 5,
            'deposit' => 2500,
            'discount' => 0,
            'other_fees' => 0,
            'has_delivery_service' => false,
            'delivery_service_fee' => 0,
        ])->assertOk()
            ->assertJsonPath('data.has_delivery_service', false)
            ->assertJsonPath('data.delivery_service_fee', 0)
            ->assertJsonPath('data.delivery_service_notes', null);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDays(3)->toIso8601String(),
            'planned_ends_at' => now()->addDays(4)->toIso8601String(),
            'daily_rate' => 1000,
            'has_delivery_service' => false,
            'delivery_service_fee' => 999,
            'delivery_service_notes' => 'يجب أن تُتجاهل',
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.has_delivery_service', false)
            ->assertJsonPath('data.delivery_service_fee', 0)
            ->assertJsonPath('data.delivery_service_notes', null);
    }

    public function test_completed_contract_posts_delivery_fee_to_account_4400_and_cancel_keeps_fields(): void
    {
        $id = (int) $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addHours(20)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2500,
            'has_delivery_service' => true,
            'delivery_service_fee' => 1000,
            'delivery_service_notes' => 'استلام من المعرض',
            'payment_amount' => 1000,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'DEP-DEL-1',
        ])->assertCreated();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 20000,
        ])->assertOk();
        $this->postJson("/api/contracts/{$id}/return", [
            'odometer_in' => 20100,
            'damage_fee' => 0,
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $contract = Contract::query()->findOrFail($id);
        $this->assertSame(2000, (int) $contract->final_total);
        $this->assertSame(1000, (int) $contract->delivery_service_fee);

        $deliveryAccountId = (int) Account::query()->where('code', '4400')->value('id');
        $credited = (int) JournalLine::query()
            ->where('account_id', $deliveryAccountId)
            ->where('credit', '>', 0)
            ->sum('credit');
        $this->assertSame(1000, $credited);

        $cancelId = (int) $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'planned_ends_at' => now()->addDays(6)->toIso8601String(),
            'daily_rate' => 1000,
            'has_delivery_service' => true,
            'delivery_service_fee' => 700,
            'delivery_service_notes' => 'ملغى لاحقاً',
            'payment_amount' => 700,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(7)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$cancelId}/cancel", [
            'reason' => 'طلب العميل',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $cancelled = Contract::query()->findOrFail($cancelId);
        $this->assertTrue((bool) $cancelled->has_delivery_service);
        $this->assertSame(700, (int) $cancelled->delivery_service_fee);
        $this->assertSame('ملغى لاحقاً', $cancelled->delivery_service_notes);
    }
}
