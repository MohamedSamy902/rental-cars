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

class AccountingMonthLedgerTest extends TestCase
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
            'operational' => ['require_handover_signature' => false, 'block_expired_license' => false, 'block_expired_vehicle_docs' => false],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true, 'name' => 'مدير الحسابات']));
    }

    public function test_month_ledger_breaks_down_money_and_records_lock_trail(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل الشهر', 'phone' => '01000000001', 'birth_date' => '1990-01-01',
            'license_number' => 'L-9', 'license_expires_on' => now()->addYear()->toDateString(), 'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'M-1', 'plate' => 'MON 1', 'brand' => 'تويوتا', 'model' => 'كورولا', 'year' => 2023,
            'category' => 'اقتصادي', 'fuel_type' => 'بنزين', 'odometer' => 5000, 'daily_rate' => 1000,
            'default_deposit' => 2000, 'is_active' => true,
        ]);

        $id = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->startOfDay()->toIso8601String(),
            'planned_ends_at' => now()->startOfDay()->addHours(20)->toIso8601String(),
            'daily_rate' => 1000,
            'deposit' => 2000,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit", ['method' => 'cash', 'receipt_number' => 'R-9'])->assertCreated();
        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 5000, 'signature_data' => 'sig-'.str_repeat('x', 40)])->assertOk();
        $this->postJson("/api/contracts/{$id}/return", ['odometer_in' => 5100, 'damage_fee' => 300, 'signature_data' => 'sig-'.str_repeat('x', 40)])->assertOk();
        $this->postJson("/api/contracts/{$id}/deposit/deduct", ['amount' => 300, 'reason' => 'خدش'])->assertOk();

        $this->postJson('/api/expenses', [
            'title' => 'غسيل', 'category' => 'تشغيل', 'amount' => 250,
            'spent_on' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertCreated();

        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');
        $ledger = $this->getJson("/api/accounting/months/{$year}/{$month}")->assertOk()->json('data');

        $this->assertGreaterThan(0, $ledger['summary']['rent_revenue']);
        $this->assertSame(300, $ledger['summary']['fees_revenue']);
        $this->assertSame(250, $ledger['summary']['expenses']);
        $this->assertSame(2000, $ledger['summary']['deposits_received']);
        $this->assertSame(300, $ledger['summary']['deposit_deductions']);
        $this->assertSame(
            $ledger['summary']['rent_revenue'] + 300 - 250,
            $ledger['summary']['net_operating'],
        );
        $this->assertSame('عميل الشهر', $ledger['rents'][0]['customer']);
        $this->assertSame('أضرار', $ledger['fees'][0]['kind']);
        $this->assertSame([], $ledger['audit']);

        $this->postJson('/api/accounting/lock-month', compact('year', 'month'))->assertOk();
        $this->postJson('/api/accounting/reopen-month', ['year' => $year, 'month' => $month, 'reason' => 'تصحيح قيد'])->assertOk();

        $this->getJson("/api/accounting/months/{$year}/{$month}")
            ->assertOk()
            ->assertJsonPath('data.lock.locked', false)
            ->assertJsonPath('data.lock.reopen_reason', 'تصحيح قيد')
            ->assertJsonCount(2, 'data.audit')
            ->assertJsonPath('data.audit.0.action', 'month_reopened')
            ->assertJsonPath('data.audit.0.reason', 'تصحيح قيد')
            ->assertJsonPath('data.audit.1.user', 'مدير الحسابات');

        $this->getJson("/api/contracts/{$id}")
            ->assertOk()
            ->assertJsonPath('data.number', fn ($n) => is_string($n) && $n !== '')
            ->assertJsonPath('data.ledger.deposit_held', 1700)
            ->assertJsonCount(2, 'data.inspections')
            ->assertJsonPath('data.payments.0.link_type', 'deposit');
    }

    public function test_invalid_month_is_rejected(): void
    {
        $this->getJson('/api/accounting/months/2026/13')->assertStatus(422);
    }
}
