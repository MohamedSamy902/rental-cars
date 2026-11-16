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
 * Cross-month rent must land on each service month card (RSD 3.6) — not all in the start month.
 */
class AccountsMonthRentSplitTest extends TestCase
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
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_overview_shows_future_month_share_not_all_in_november(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00', 'Africa/Cairo'));

        $customer = Customer::query()->create([
            'full_name' => 'عميل عبر الشهور',
            'phone' => '01099998888',
            'birth_date' => '1990-01-01',
            'license_number' => 'LX-SPLIT',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'SPLIT-1',
            'plate' => 'SP 1',
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

        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-11-16T12:00:00+02:00',
            'planned_ends_at' => '2026-12-16T12:00:00+02:00',
            'daily_rate' => 1000,
            'half_monthly_rate' => 15000,
            'monthly_rate' => 30000, // وحدة ٣٠ يوماً = ٣٠٠٠٠ حتى يبقى إجمالي المدة ٣٠٠٠٠ لاختبار التوزيع
            'deposit' => 0,
            'odometer' => 1000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 1000,
            'signature_data' => 'sig-'.str_repeat('x', 40),
        ])->assertOk();

        $this->assertNotNull(
            JournalEntry::query()->where('source', sprintf('rent_recog:%d:2026-11', $id))->first(),
            'November rent recognition must post on handover',
        );
        $this->assertNotNull(
            JournalEntry::query()->where('source', sprintf('rent_recog:%d:2026-12', $id))->first(),
            'December rent recognition must post on handover',
        );

        $months = collect($this->getJson('/api/accounting/overview')->assertOk()->json('data.months'));
        $nov = $months->first(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 11);
        $dec = $months->first(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 12);

        $this->assertNotNull($nov, 'November card must appear');
        $this->assertNotNull($dec, 'December card must appear while still in November');
        $this->assertGreaterThan(0, (int) $nov['rent_revenue']);
        $this->assertGreaterThan(0, (int) $dec['rent_revenue']);
        $this->assertSame(30000, (int) $nov['rent_revenue'] + (int) $dec['rent_revenue']);
        $this->assertLessThan(30000, (int) $nov['rent_revenue'], 'November must not hold the full period rent');

        $decIndex = $months->search(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 12);
        $novIndex = $months->search(fn (array $m) => (int) $m['year'] === 2026 && (int) $m['month'] === 11);
        $this->assertLessThan($novIndex, $decIndex, 'December must sort above November (newest first)');

        Carbon::setTestNow();
    }
}
