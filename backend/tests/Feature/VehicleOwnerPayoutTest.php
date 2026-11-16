<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleOwnerPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $frozen = CarbonImmutable::parse('2026-12-01 12:00:00', 'Africa/Cairo');
        Carbon::setTestNow($frozen);
        CarbonImmutable::setTestNow($frozen);
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
            'phone' => '01070070070',
            'email' => 'owner-admin@rpm.local',
            'password' => Hash::make('password'),
        ]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_monthly_payout_posts_expense_without_accrual_cap(): void
    {
        $owner = $this->postJson('/api/owners', [
            'full_name' => 'حسام المالك',
            'phone' => '01099998888',
        ])->assertCreated()->json('data');

        $since = '2026-11-01';
        $v1 = Vehicle::query()->create([
            'code' => 'O-1', 'plate' => 'OWN 1', 'brand' => 'هيونداي', 'model' => 'إلينترا',
            'year' => 2021, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 10000, 'daily_rate' => 900, 'default_deposit' => 2000, 'is_active' => true,
            'owner_id' => $owner['id'], 'owner_monthly_amount' => 20000,
            'owner_since' => $since,
            'owner_agreement_starts_on' => $since,
            'owner_agreement_ends_on' => '2027-11-01',
        ]);

        $show = $this->getJson('/api/owners/'.$owner['id'])->assertOk()->json('data');
        $this->assertSame(20000, $show['balance']['vehicles_monthly_total']);
        // اليوم 2026-12-01 → نوفمبر مكتمل وغير مصروف = متأخر 20000
        $this->assertSame(20000, $show['balance']['earned_total']);
        $this->assertSame(20000, $show['balance']['overdue']);
        $this->assertSame(20000, $show['balance']['remaining']);

        $payout = $this->postJson('/api/owners/'.$owner['id'].'/payouts', [
            'amount' => 20000,
            'paid_on' => now()->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $v1->id,
            'notes' => 'صرف الشهر',
        ])->assertCreated();

        $this->assertSame(20000, $payout->json('data.amount'));
        $this->assertSame(20000, $payout->json('balance.paid_total'));
        $this->assertSame(0, $payout->json('balance.overdue'));
        $this->assertSame(0, $payout->json('balance.remaining'));
        $this->assertDatabaseCount('owner_payouts', 1);
        $this->assertDatabaseCount('owner_month_dues', 0);

        $expense = Account::query()->where('code', '5200')->firstOrFail();
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $this->assertTrue(
            JournalLine::query()->where('account_id', $expense->id)->where('debit', 20000)->exists()
        );
        $this->assertTrue(
            JournalLine::query()->where('account_id', $cash->id)->where('credit', 20000)->exists()
        );

        // صرف إضافي مسموح (لا سقف متبقي محاسبي)
        $this->postJson('/api/owners/'.$owner['id'].'/payouts', [
            'amount' => 5000,
            'paid_on' => now()->toDateString(),
            'payment_method' => 'transfer',
        ])->assertCreated()->assertJsonPath('balance.paid_total', 25000);
    }

    public function test_vehicle_store_links_owner_monthly_amount(): void
    {
        $ownerId = VehicleOwner::query()->create([
            'full_name' => 'مالك تجريبي',
            'phone' => '01011110000',
            'is_active' => true,
        ])->id;

        $res = $this->postJson('/api/vehicles', [
            'code' => 'OWN-99',
            'plate' => 'XYZ 99',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 3500,
            'owner_agreement_starts_on' => '2026-11-10',
            'owner_agreement_ends_on' => '2026-12-09',
        ])->assertCreated();

        $this->assertSame($ownerId, $res->json('data.owner_id'));
        $this->assertSame(3500, $res->json('data.owner_monthly_amount'));
        $this->assertNotNull($res->json('data.owner_since'));
        $this->assertSame('مالك تجريبي', $res->json('data.owner.full_name'));
    }
}
